<?php

namespace App\Http\Controllers;

use App\Jobs\CopyOriginalPhotoJob;
use App\Jobs\EditPhotoItemJob;
use App\Jobs\PushEditedPhotoJob;
use App\Jobs\GenerateLifestyleImageJob;
use App\Jobs\ScanPhotoEditFolderJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\Store;
use App\Models\User;
use App\Services\OneDriveService;
use App\Services\ImageProcessingService;
use App\Services\PhotoroomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fetch product photos from OneDrive, edit them through Photoroom, review the
 * results, then push the keepers to Shopify.
 *
 * Separate from BulkUploadController on purpose: a bulk upload is one
 * unattended run from folder to Shopify, while this stops in the middle and
 * waits for a person to decide what was worth keeping.
 */
class PhotoEditorController extends Controller implements HasMiddleware
{
    public function __construct(
        private PhotoroomService $photoroom,
    ) {}

    /**
     * Gated on the feature permission, not just hidden from the sidebar. Every
     * run here spends Photoroom credit, so reaching the URL directly must not
     * be enough.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, \Closure $next) {
                abort_unless($request->user()?->hasFeature('photo_editor'), 403, 'You do not have access to the Photo Editor.');
                return $next($request);
            }),
        ];
    }

    // ── Views ──────────────────────────────────────────────────────────────

    public function index(): View
    {
        $user = auth()->user();

        return view('photo-editor.index', [
            'activeStore'          => Store::getActive(),
            'onedriveConfigured'   => !empty($user->onedrive_access_token),
            'photoroomConfigured'  => $this->photoroom->isConfigured(),
            'isSandbox'            => $this->photoroom->isSandbox(),
            'maxImages'            => (int) config('services.photoroom.max_images', 120),

            // What the settings block on the form starts filled in with. Same
            // array the session is created with, so the two cannot drift.
            'defaultEdits'         => PhotoroomService::defaultEdits(),
            'retentionDays'        => (int) config('services.photoroom.retention_days', 7),
            'recent'               => $this->scope()->with('store')->latest()->limit(5)->get(),

            // The option lists live on the service, so the form, the validation
            // rules and the API mapping can never disagree about what exists.
            'sizePresets'   => PhotoroomService::SIZE_PRESETS,
            'vmModels'      => PhotoroomService::VIRTUAL_MODEL_PRESETS,
            'vmScenes'      => PhotoroomService::VIRTUAL_MODEL_SCENES,
            'vmPoses'       => PhotoroomService::VIRTUAL_MODEL_POSES,
            'shadowModes'   => PhotoroomService::SHADOW_MODES,
            'shadowSpreads' => PhotoroomService::SHADOW_SPREADS,
            'shadowDirs'    => PhotoroomService::SHADOW_DIRECTIONS,
            'shadowPoses'   => PhotoroomService::SHADOW_POSES,
            // Food and Vehicles are trained for subjects this catalogue does
            // not sell; offering them only invites a silent no-op. The
            // service still accepts them for anything set before now.
            'beautifyModes' => array_intersect_key(
                PhotoroomService::BEAUTIFY_MODES,
                array_flip(['', 'ai.auto']),
            ),
            'textModes'     => PhotoroomService::TEXT_REMOVAL_MODES,

            // Straightening happens on our side, before the upload, so its
            // options belong to the local image service rather than Photoroom.
            'rotations'     => ImageProcessingService::INPUT_ROTATIONS,
        ]);
    }

    public function history(): View
    {
        return view('photo-editor.history', [
            'sessions' => $this->scope()->with('store')->latest()->paginate(20),
        ]);
    }

    public function show(PhotoEditSession $session): View
    {
        $this->authorizeSession($session);

        return view('photo-editor.show', [
            'session'       => $session,
            'isSandbox'     => $this->photoroom->isSandbox(),
            'retentionDays' => (int) config('services.photoroom.retention_days', 7),
        ]);
    }

    // ── Actions ────────────────────────────────────────────────────────────

    /**
     * Start a run: where the photos are, and what should be done to them.
     *
     * The settings are chosen here for the folder as a whole. A run of thirty
     * SKUs that all want the same treatment is one decision, not thirty
     * identical ones — and the operator usually knows what they shot before
     * they paste the link. The configure screen still opens afterwards
     * carrying these forward, so a SKU that genuinely wants something else can
     * still be set to differ against the actual photos, and nothing reaches
     * Photoroom until it is started from there.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'onedrive_link'    => ['required', 'string', 'max:2048'],
            'name'             => ['nullable', 'string', 'max:120'],
            'matching_mode'    => ['required', 'in:sku_barcode,style_code'],

            'input_rotation'   => ['nullable', Rule::in(array_keys(ImageProcessingService::INPUT_ROTATIONS))],
            'rotate_wide_only' => ['nullable', 'boolean'],

            // Shape-checked here and field-checked in editsFromRequest, which
            // is the same path the configure screen's settings take.
            'edits'            => ['nullable', 'array'],
        ], [
            'onedrive_link.required' => 'Paste the OneDrive or SharePoint folder link.',
        ]);

        $rotation = (string) ($validated['input_rotation'] ?? '');

        /*
         * Absent rather than empty is the case that matters: a request that
         * posts no settings at all keeps every default, where merging an empty
         * array over them would read each unticked box as a deliberate "no"
         * and switch the cutout itself off.
         */
        $edits = PhotoroomService::defaultEdits();

        if (!empty($validated['edits'])) {
            $edits = $this->editsFromRequest($validated['edits'], $edits);
        }

        // Orientation is the one edit that belongs to the shoot rather than
        // the product: a camera that wrote every frame sideways wrote them all
        // sideways, whatever was in front of it. Applied after the merge, since
        // it is asked for outside that block of settings.
        $edits['input_rotation'] = $rotation ?: null;

        // "Only the ones that came out wide" is a quarter-turn idea. A 180°
        // flip leaves a photo the same shape it started, so the limit would
        // silently match everything or nothing.
        $edits['rotate_wide_only'] = in_array($rotation, ['right', 'left'], true)
            && !empty($validated['rotate_wide_only']);

        $session = PhotoEditSession::create([
            'user_id'       => auth()->id(),
            'store_id'      => Store::getActive()?->id,
            'name'          => ($validated['name'] ?? null) ?: 'Edit ' . now()->format('Y-m-d H:i'),
            'onedrive_link' => $validated['onedrive_link'],
            'matching_mode' => $validated['matching_mode'],

            // Followed by every SKU group unless one is set to differ.
            'edits'         => $edits,

            'status'      => 'processing',
            'scan_status' => 'pending',
        ]);

        ScanPhotoEditFolderJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('photo-editor.configure', $session)
            ->with('info', 'Reading the folder. Your settings are carried over — nothing is sent to Photoroom until you start the run.');
    }

    /**
     * Progress + results, polled by the review page while editing runs.
     */
    /**
     * The screen between finding the photos and spending anything on them.
     *
     * The run's settings arrive here already answered on the first screen and
     * are shown back for a last look. What this screen adds is what could not
     * be asked before the folder was read: the SKUs that want something other
     * than the run's answer — a watch face wants no padding where a dress
     * wants plenty — the on-model images, and the credit total.
     */
    public function configure(PhotoEditSession $session)
    {
        $this->authorizeSession($session);

        $groups = PhotoEditGroup::where('photo_edit_session_id', $session->id)
            ->orderBy('sku')
            ->get();

        $photos = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'cutout')
            ->inDisplayOrder()
            ->get(['id', 'sku_detected', 'filename', 'original_size_kb', 'position'])
            ->groupBy('sku_detected');

        return view('photo-editor.configure', [
            'session'       => $session,
            'groups'        => $groups,
            'photos'        => $photos,
            'beautifyModes' => array_intersect_key(
                PhotoroomService::BEAUTIFY_MODES,
                array_flip(['', 'ai.auto']),
            ),
            'maxLifestyle'  => PhotoEditGroup::MAX_LIFESTYLE,
            'monthlyQuota'  => (int) config('services.photoroom.monthly_quota', 1000),
        ]);
    }

    /**
     * Save what each SKU should get, then start the run.
     *
     * Saving and starting are one action on purpose. A half-configured run that
     * sits waiting is a run somebody comes back to a week later having
     * forgotten what they decided, and the photos it points at may not be in
     * OneDrive any more.
     */
    public function start(Request $request, PhotoEditSession $session)
    {
        $this->authorizeSession($session);

        if ($session->status !== 'configuring') {
            return back()->with('error', 'This run has already been started.');
        }

        $validated = $request->validate([
            'edits'                    => ['nullable', 'array'],
            'groups'                   => ['required', 'array'],
            'groups.*.lifestyle_count' => ['nullable', 'integer', 'min:0', 'max:' . PhotoEditGroup::MAX_LIFESTYLE],
            'groups.*.lifestyle_source_item_id' => ['nullable', 'integer'],
            'groups.*.differs'         => ['nullable', 'boolean'],
            'groups.*.edits'           => ['nullable', 'array'],
            'groups.*.order'           => ['nullable', 'array'],
            'groups.*.order.*'         => ['integer'],
            'groups.*.as_is'           => ['nullable', 'array'],
            'groups.*.as_is.*'         => ['integer'],
        ]);

        // The run's own settings, which every group follows unless it opted out.
        if (!empty($validated['edits'])) {
            $session->edits = $this->editsFromRequest($validated['edits'], $session->edits ?? []);
            $session->save();
        }

        $groups = PhotoEditGroup::where('photo_edit_session_id', $session->id)->get()->keyBy('id');

        /*
         * Driven by the groups that exist, not by what the form posted.
         * validated() drops an entry whose every sub-key is absent, so a SKU
         * left entirely alone — the common case, now that following the run is
         * the default — never appeared here and kept whatever it had before.
         */
        foreach ($groups as $groupId => $group) {
            $input = (array) $request->input("groups.{$groupId}", []);

            $group->fill([
                'lifestyle_count' => (int) ($input['lifestyle_count'] ?? 0),
                'lifestyle_source_item_id' => $input['lifestyle_source_item_id'] ?? null,
            ]);

            /*
             * Null means "follows the run". Storing a copy instead would pin
             * the group to today's settings, so changing the run later would
             * quietly leave every SKU behind on the old ones.
             */
            $group->edits = !empty($input['differs'])
                ? $this->editsFromRequest($input['edits'] ?? [], $group->edits ?: ($session->edits ?? []))
                : null;

            $this->saveOrder($session, (array) ($input['order'] ?? []));
            $this->saveUntouched($session, $group->sku, (array) ($input['as_is'] ?? []));

            // A count without a photo to build from would queue work that can
            // only fail, so it is refused here rather than at the API.
            if (!$group->lifestyleSourceIsValid()) {
                return back()
                    ->withInput()
                    ->with('error', "Pick which photo the model should wear for {$group->sku}, or set its lifestyle count back to 0.");
            }

            $group->save();
        }

        $this->queueRun($session, $groups->keys()->all());

        return redirect()->route('photo-editor.show', $session)
            ->with('info', 'Editing has started. Nothing reaches Shopify until you pick the results.');
    }

    /**
     * A preview of an original, straight from OneDrive.
     *
     * The configure screen shows every photo before a credit is spent, and the
     * originals are ~9 MB each — Graph renders previews for free, so those are
     * served instead of pulling the full files.
     */
    public function onedriveThumb(PhotoEditSession $session, PhotoEditItem $item, OneDriveService $oneDrive)
    {
        $this->authorizeSession($session);

        abort_unless($item->photo_edit_session_id === $session->id, 404);

        if ($session->user_id && ($user = User::find($session->user_id))) {
            $oneDrive->setUser($user);
        }

        $bytes = $oneDrive->thumbnailBytes($item->onedrive_drive_id, $item->onedrive_item_id);

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * Queue the cutouts, then the on-model images each group asked for.
     *
     * Lifestyle rows are created here rather than at scan time because until
     * now nobody had said how many there should be.
     */
    private function queueRun(PhotoEditSession $session, array $groupIds): void
    {
        $session->update(['status' => 'processing']);

        /*
         * Two routes, one queue. A photo marked "as is" is fetched and filed
         * without ever reaching Photoroom — it ends in the same state as an
         * edited one, so the review screen and the push cannot tell them apart
         * and do not need to.
         */
        PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'cutout')
            ->where('status', 'pending')
            ->select('id', 'skip_edit')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $item->skip_edit
                        ? CopyOriginalPhotoJob::dispatch($item->id)->onQueue('bulkupload')
                        : EditPhotoItemJob::dispatch($item->id)->onQueue('bulkupload');
                }
            });

        foreach (PhotoEditGroup::whereIn('id', $groupIds)->where('lifestyle_count', '>', 0)->get() as $group) {
            $source = PhotoEditItem::find($group->lifestyle_source_item_id);

            if (!$source) {
                continue;
            }

            for ($i = 0; $i < $group->lifestyle_count; $i++) {
                $item = PhotoEditItem::create([
                    'photo_edit_session_id' => $session->id,
                    'kind'                  => 'lifestyle',
                    'source_item_id'        => $source->id,
                    'filename'              => pathinfo($source->filename, PATHINFO_FILENAME) . '-lifestyle-' . ($i + 1) . '.jpg',
                    'sku_detected'          => $group->sku,
                    'status'                => 'pending',
                    'selected'              => false, // generated, so opted into rather than out of
                ]);

                GenerateLifestyleImageJob::dispatch($item->id, $i)->onQueue('bulkupload');
            }
        }

        $session->update([
            'total_files' => PhotoEditItem::where('photo_edit_session_id', $session->id)->count(),
        ]);
    }

    /**
     * Merge a group's submitted settings over the ones it already had.
     *
     * Both forms that post these — the first screen and the configure screen —
     * send only the fields they show. Anything they do not show, such as an
     * option trimmed from the UI but still stored on older runs, is kept
     * rather than silently reset to a default nobody chose.
     */
    private function editsFromRequest(array $input, array $existing): array
    {
        $booleans = ['remove_background', 'upscale', 'expand', 'ironing', 'rotate_wide_only',
            'snap_cropped_sides', 'surgical_erase'];

        foreach ($booleans as $key) {
            $input[$key] = !empty($input[$key]);
        }

        foreach (['width', 'height', 'max_width', 'max_height', 'lifestyle_count'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = filled($input[$key]) ? (int) $input[$key] : null;
            }
        }

        foreach (['padding', 'trim_top', 'trim_bottom'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = filled($input[$key]) ? (float) $input[$key] : null;
            }
        }

        /*
         * Per-edge spacing is typed in pixels and stored with its unit, so
         * nothing downstream has to infer what a bare number meant. The overall
         * slider stays a fraction — it is a proportion of whatever canvas the
         * group ends up with, which is a different question from "40 pixels".
         */
        foreach (['padding_top', 'padding_bottom', 'padding_left', 'padding_right'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = filled($input[$key])
                    ? ((int) round(max(0, min(2000, (float) $input[$key])))) . 'px'
                    : null;
            }
        }

        $merged = array_merge($existing, $input);

        /*
         * The category framing is expanded here rather than taken from the
         * form. The screen fills those boxes in as a preview and then locks
         * them, so what a browser posts back for them is incomplete by design
         * — and a request that never ran the JS at all would otherwise store a
         * category name beside framing that does not match it.
         */
        return PhotoroomService::applyFramingPreset(
            $merged,
            array_key_exists('framing_preset', $input)
                ? $input['framing_preset']
                : ($existing['framing_preset'] ?? null),
        );
    }

    /**
     * Store the order the thumbnails were dragged into.
     *
     * Positions start at 1 so that 0 keeps meaning "never ordered", which is
     * what lets an older run fall back to filename order untouched.
     *
     * Scoped to the session on purpose: the ids arrive from a form and a
     * doctored one would otherwise renumber photos in somebody else's run.
     */
    private function saveOrder(PhotoEditSession $session, array $itemIds): void
    {
        if (!$itemIds) {
            return;
        }

        foreach (array_values($itemIds) as $index => $itemId) {
            PhotoEditItem::where('photo_edit_session_id', $session->id)
                ->whereKey($itemId)
                ->update(['position' => $index + 1]);
        }
    }

    /**
     * Mark which of a SKU's photos go to Shopify untouched.
     *
     * Written for the whole SKU rather than only the ticked ones, so unticking
     * a photo puts it back in the edit queue — a list of additions alone could
     * never take one out again.
     *
     * Scoped to the session and the SKU for the same reason saveOrder is: the
     * ids come from a form, and a doctored one must not be able to reach into
     * another run and quietly stop its photos being edited.
     */
    private function saveUntouched(PhotoEditSession $session, string $sku, array $itemIds): void
    {
        $scope = fn () => PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('sku_detected', $sku)
            ->where('kind', 'cutout');

        $scope()->update(['skip_edit' => false]);

        if ($itemIds) {
            $scope()->whereIn('id', $itemIds)->update(['skip_edit' => true]);
        }
    }

    public function status(Request $request, PhotoEditSession $session): JsonResponse
    {
        $this->authorizeSession($session);

        $counts = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'edited'  THEN 1 ELSE 0 END) as edited,
                SUM(CASE WHEN status = 'pushed'  THEN 1 ELSE 0 END) as pushed,
                SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status IN ('editing', 'pushing') THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        $total = max($session->total_files, (int) ($counts->total ?? 0));
        $done  = (int) ($counts->edited ?? 0) + (int) ($counts->pushed ?? 0)
               + (int) ($counts->failed ?? 0) + (int) ($counts->skipped ?? 0);

        // Grouped by SKU and then in the order the thumbnails were arranged, so
        // the review grid reads the same way the product page will.
        $paginator = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->orderBy('sku_detected')
            ->inDisplayOrder()
            ->paginate(60, ['*'], 'page', max(1, (int) $request->get('page', 1)));

        $items = $paginator->getCollection()->map(fn (PhotoEditItem $i) => [
            'id'                    => $i->id,
            'filename'              => $i->filename,
            'sku'                   => $i->sku_detected,
            'status'                => $i->status,
            'status_label'          => $i->statusLabel(),
            'status_color'          => $i->statusColor(),
            'view_type'             => $i->view_type,
            'mannequin_visible'     => $i->mannequin_visible,
            'apparel_mode_applied'  => $i->apparel_mode_applied,

            // Photoroom's own confidence in the cutout, so the reviewer's
            // attention lands on the ones it was unsure about rather than
            // being spread evenly across a batch that is mostly fine.
            'uncertainty_score'     => $i->uncertainty_score,
            'looks_uncertain'       => $i->looksUncertain(),
            'selected'              => (bool) $i->selected,
            'pushable'              => $i->isPushable(),
            'edited_kb'             => $i->edited_size_kb,
            'original_kb'           => $i->original_size_kb,
            'product_title'         => $i->product_title,
            'error'                 => $i->error_message,
            'before_url'            => $i->original_thumb_path ? route('photo-editor.preview', [$session, $i, 'before']) : null,
            'after_url'             => $i->edited_thumb_path   ? route('photo-editor.preview', [$session, $i, 'after'])  : null,
            'full_url'              => $i->edited_path         ? route('photo-editor.preview', [$session, $i, 'full'])   : null,
        ]);

        return response()->json([
            'session' => [
                'id'          => $session->id,
                'status'      => $session->status,
                'scan_status' => $session->scan_status,
                'is_finished' => $session->isFinished(),
                'progress'    => $total > 0 ? (int) min(100, round($done / $total * 100)) : 0,
                'total'       => $total,
                'edited'      => (int) ($counts->edited ?? 0),
                'pushed'      => (int) ($counts->pushed ?? 0),
                'failed'      => (int) ($counts->failed ?? 0),
                'skipped'     => (int) ($counts->skipped ?? 0),
                'working'     => (int) ($counts->working ?? 0),
                'pending'     => (int) ($counts->pending ?? 0),
                'error'       => $session->error_message,
            ],
            'items'      => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Serve one stored image. These never become public URLs — the files sit
     * outside the web root and every read passes the same ownership check as
     * the session itself.
     */
    public function preview(PhotoEditSession $session, PhotoEditItem $item, string $variant): BinaryFileResponse
    {
        $this->authorizeSession($session);

        abort_unless($item->photo_edit_session_id === $session->id, 404);

        $relative = match ($variant) {
            'before' => $item->original_thumb_path,
            'after'  => $item->edited_thumb_path,
            'full'   => $item->edited_path,
            default  => null,
        };

        abort_unless($relative, 404);

        $path = storage_path('app/' . $relative);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Send the chosen edits to Shopify.
     *
     * The selection is stored as it is acted on, so reopening the page shows
     * what was actually chosen rather than resetting to "everything".
     */
    public function push(Request $request, PhotoEditSession $session): JsonResponse
    {
        $this->authorizeSession($session);

        $validated = $request->validate([
            'item_ids'   => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ]);

        $ids = $validated['item_ids'];

        PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->update(['selected' => false]);

        PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->whereIn('id', $ids)
            ->update(['selected' => true]);

        // Only items that actually edited cleanly and still have their file can
        // go — anything else in the list is silently dropped rather than
        // queuing a job that is certain to fail.
        $pushable = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->whereIn('id', $ids)
            ->where('status', 'edited')
            ->whereNotNull('edited_path')
            ->pluck('id');

        foreach ($pushable as $id) {
            PushEditedPhotoJob::dispatch($id)->onQueue('bulkupload');
        }

        return response()->json([
            'queued'  => $pushable->count(),
            'skipped' => count($ids) - $pushable->count(),
        ]);
    }

    /**
     * Send one photo back through Photoroom. Worth having on its own: the
     * generative steps (AI background, mannequin removal) are non-deterministic,
     * so a disliked result often comes out differently on a second attempt
     * without touching anything else in the session.
     */
    public function reedit(PhotoEditSession $session, PhotoEditItem $item): JsonResponse
    {
        $this->authorizeSession($session);

        abort_unless($item->photo_edit_session_id === $session->id, 404);

        if (in_array($item->status, ['editing', 'pushing'], true)) {
            return response()->json(['error' => 'This photo is still processing.'], 409);
        }

        // Reset away from a terminal status: EditPhotoItemJob refuses to
        // touch an item that already reads 'edited'/'pushed'/'skipped', on
        // the assumption that status means the work is done.
        $item->update([
            'status'        => 'pending',
            'error_message' => null,
        ]);

        EditPhotoItemJob::dispatch($item->id)->onQueue('bulkupload');

        return response()->json(['status' => 'queued']);
    }

    public function destroy(PhotoEditSession $session): RedirectResponse
    {
        $this->authorizeSession($session);

        $name = $session->name;

        // Files first: losing the rows while the images stay behind would leave
        // bytes on disk that nothing knows how to find again.
        $session->deleteFiles();
        $session->items()->delete();
        $session->delete();

        return redirect()->route('photo-editor.history')
            ->with('success', "Session \"{$name}\" and its edited files were deleted.");
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Super admins see every session; everyone else sees only their own. */
    private function scope()
    {
        $user = auth()->user();

        return $user->is_super_admin
            ? PhotoEditSession::query()
            : PhotoEditSession::where('user_id', $user->id);
    }

    private function authorizeSession(PhotoEditSession $session): void
    {
        $user = auth()->user();

        if (!$user->is_super_admin && $session->user_id !== $user->id) {
            abort(403);
        }
    }
}
