<?php

namespace App\Http\Controllers;

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
     * Start a run: find the photos, decide nothing about them yet.
     *
     * This screen used to collect every Photoroom setting and apply the lot to
     * whatever the folder turned out to hold — which meant committing a run of
     * dresses, watches and caps to one answer before anybody had seen a single
     * photo. All of that moved to the configure screen, where the settings are
     * chosen per SKU against the actual images. What is left here is only what
     * has to be known before the folder can be read at all.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'onedrive_link'    => ['required', 'string', 'max:2048'],
            'name'             => ['nullable', 'string', 'max:120'],
            'matching_mode'    => ['required', 'in:sku_barcode,style_code'],

            // Orientation is the one edit that belongs to the shoot rather than
            // the product: a camera that wrote every frame sideways wrote them
            // all sideways, whatever was in front of it.
            'input_rotation'   => ['nullable', Rule::in(array_keys(ImageProcessingService::INPUT_ROTATIONS))],
            'rotate_wide_only' => ['nullable', 'boolean'],
        ], [
            'onedrive_link.required' => 'Paste the OneDrive or SharePoint folder link.',
        ]);

        $rotation = (string) ($validated['input_rotation'] ?? '');

        $session = PhotoEditSession::create([
            'user_id'       => auth()->id(),
            'store_id'      => Store::getActive()?->id,
            'name'          => ($validated['name'] ?? null) ?: 'Edit ' . now()->format('Y-m-d H:i'),
            'onedrive_link' => $validated['onedrive_link'],
            'matching_mode' => $validated['matching_mode'],

            // The starting point every SKU group is created with. Not a
            // decision anybody has made — the configure screen is where those
            // happen.
            'edits'         => array_merge(PhotoroomService::defaultEdits(), [
                'input_rotation'   => $rotation ?: null,

                // "Only the ones that came out wide" is a quarter-turn idea.
                // A 180° flip leaves a photo the same shape it started, so the
                // limit would silently match everything or nothing.
                'rotate_wide_only' => in_array($rotation, ['right', 'left'], true)
                    && !empty($validated['rotate_wide_only']),
            ]),

            'status'      => 'processing',
            'scan_status' => 'pending',
        ]);

        ScanPhotoEditFolderJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('photo-editor.configure', $session)
            ->with('info', 'Reading the folder. Nothing is sent to Photoroom until you say what each SKU should get.');
    }

    /**
     * Progress + results, polled by the review page while editing runs.
     */
    /**
     * The screen between finding the photos and spending anything on them.
     *
     * Each SKU folder gets its own settings because a run routinely mixes
     * product types that want opposite treatment, and the old flow committed
     * every one of them to a single answer chosen before anybody had seen a
     * photo.
     */
    public function configure(PhotoEditSession $session)
    {
        $this->authorizeSession($session);

        $groups = PhotoEditGroup::where('photo_edit_session_id', $session->id)
            ->orderBy('sku')
            ->get();

        $photos = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'cutout')
            ->orderBy('filename')
            ->get(['id', 'sku_detected', 'filename', 'original_size_kb'])
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
            'groups'                   => ['required', 'array'],
            'groups.*.lifestyle_count' => ['nullable', 'integer', 'min:0', 'max:' . PhotoEditGroup::MAX_LIFESTYLE],
            'groups.*.lifestyle_source_item_id' => ['nullable', 'integer'],
            'groups.*.edits'           => ['nullable', 'array'],
        ]);

        $groups = PhotoEditGroup::where('photo_edit_session_id', $session->id)->get()->keyBy('id');

        foreach ($validated['groups'] as $groupId => $input) {
            $group = $groups->get((int) $groupId);

            if (!$group) {
                continue;
            }

            $group->fill([
                'lifestyle_count' => (int) ($input['lifestyle_count'] ?? 0),
                'lifestyle_source_item_id' => $input['lifestyle_source_item_id'] ?? null,
            ]);

            if (!empty($input['edits'])) {
                $group->edits = $this->editsFromRequest($input['edits'], $group->edits ?? []);
            }

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

        PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'cutout')
            ->where('status', 'pending')
            ->select('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    EditPhotoItemJob::dispatch($item->id)->onQueue('bulkupload');
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
     * The configure form posts only the fields it shows. Anything it does not
     * show — options trimmed from the UI but still stored on older runs — is
     * kept rather than silently reset to a default nobody chose.
     */
    private function editsFromRequest(array $input, array $existing): array
    {
        $booleans = ['remove_background', 'upscale', 'expand', 'ironing', 'rotate_wide_only', 'snap_cropped_sides'];

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
         * Per-edge spacing is typed as a percentage, because that is what the
         * slider beside it shows. Stored as a fraction, because that is what
         * everything downstream already speaks — the conversion happens here so
         * the two controls can never disagree about units.
         */
        foreach (['padding_top', 'padding_bottom', 'padding_left', 'padding_right'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = filled($input[$key])
                    ? round(max(0, min(49, (float) $input[$key])) / 100, 4)
                    : null;
            }
        }

        return array_merge($existing, $input);
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

        $paginator = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->orderBy('id')
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
