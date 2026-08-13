<?php

namespace App\Http\Controllers;

use App\Jobs\PushEditedPhotoJob;
use App\Jobs\ScanPhotoEditFolderJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\Store;
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
            'maxImages'            => (int) config('services.photoroom.max_images', 300),
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
            'beautifyModes' => PhotoroomService::BEAUTIFY_MODES,
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

    public function store(Request $request): RedirectResponse
    {
        if (!$this->photoroom->isConfigured()) {
            return back()->withInput()
                ->with('error', 'No Photoroom API key is configured. Add PHOTOROOM_API_KEY to the environment first.');
        }

        $validated = $request->validate([
            'name'            => ['nullable', 'string', 'max:255'],
            'onedrive_link'   => ['required', 'url'],
            'matching_mode'   => ['required', 'in:sku_barcode,style_code'],

            // ── Straightening (applied before the upload, not by Photoroom) ──
            'input_rotation'   => ['nullable', Rule::in(array_keys(ImageProcessingService::INPUT_ROTATIONS))],
            'rotate_wide_only' => ['nullable', 'boolean'],
            'trim_top'         => ['nullable', 'numeric', 'min:0', 'max:0.4'],
            'trim_bottom'      => ['nullable', 'numeric', 'min:0', 'max:0.4'],

            // ── Background ──
            'remove_background'      => ['nullable', 'boolean'],
            'background_mode'        => ['required', Rule::in(PhotoroomService::BACKGROUND_MODES)],
            'background_color'       => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'background_prompt'      => ['nullable', 'string', 'max:500', 'required_if:background_mode,prompt'],
            'background_image_url'   => ['nullable', 'url', 'required_if:background_mode,image'],
            'background_seed'        => ['nullable', 'integer', 'min:0'],
            'background_blur_mode'   => ['nullable', 'in:gaussian,bokeh'],
            'background_blur_radius' => ['nullable', 'numeric', 'min:0', 'max:0.05'],

            // ── Clothing ──
            'apparel_mode'   => ['required', 'in:none,ghost_mannequin,flat_lay,virtual_model'],
            'apparel_size'   => ['nullable', Rule::in(array_keys(PhotoroomService::SIZE_PRESETS))],
            'apparel_prompt' => ['nullable', 'string', 'max:500'],
            'vm_model'       => ['nullable', Rule::in(PhotoroomService::VIRTUAL_MODEL_PRESETS)],
            'vm_scene'       => ['nullable', Rule::in(PhotoroomService::VIRTUAL_MODEL_SCENES)],
            'vm_pose'        => ['nullable', Rule::in(PhotoroomService::VIRTUAL_MODEL_POSES)],
            'ironing'        => ['nullable', 'boolean'],

            // ── Shadow ──
            'shadow'           => ['nullable', Rule::in(array_keys(PhotoroomService::SHADOW_MODES))],
            'shadow_softness'  => ['nullable', 'numeric', 'min:0', 'max:1'],
            'shadow_intensity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'shadow_spread'    => ['nullable', Rule::in(PhotoroomService::SHADOW_SPREADS)],
            'shadow_direction' => ['nullable', Rule::in(PhotoroomService::SHADOW_DIRECTIONS)],
            'shadow_pose'      => ['nullable', Rule::in(PhotoroomService::SHADOW_POSES)],

            // ── Finishing ──
            'text_removal'  => ['nullable', Rule::in(array_keys(PhotoroomService::TEXT_REMOVAL_MODES))],
            'beautify'      => ['nullable', Rule::in(array_keys(PhotoroomService::BEAUTIFY_MODES))],
            'lighting'      => ['nullable', 'boolean'],
            'upscale'       => ['nullable', 'boolean'],
            'expand'        => ['nullable', 'boolean'],
            'uncrop'        => ['nullable', 'boolean'],
            'outline_color' => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'outline_width' => ['nullable', 'numeric', 'min:0', 'max:0.1'],

            // ── Output ──
            'image_width'   => ['nullable', 'integer', 'min:100', 'max:5000'],
            'image_height'  => ['nullable', 'integer', 'min:100', 'max:5000'],
            'padding'       => ['nullable', 'numeric', 'min:0', 'max:0.49'],
            'h_align'       => ['required', 'in:left,center,right'],
            'v_align'       => ['required', 'in:top,center,bottom'],
            'scaling'       => ['required', 'in:fit,fill'],
            'reference_box' => ['nullable', 'in:subjectBox,originalImage'],
            'dpi'           => ['nullable', 'integer', 'min:72', 'max:1200'],
        ], [
            'background_color.regex'     => 'The background colour must be a 6-digit hex value, e.g. #F5F5F5.',
            'outline_color.regex'        => 'The outline colour must be a 6-digit hex value, e.g. #222222.',
            'background_prompt.required_if'    => 'Describe the background you want generating.',
            'background_image_url.required_if' => 'Give the URL of the background image to use.',
        ]);

        /*
         * A validated field that was never submitted is absent from the array
         * rather than null — an unticked checkbox, a shadow nobody chose. Fill
         * the optional keys in first, so reading them below cannot fail on a
         * form that simply left something out.
         */
        $validated += array_fill_keys([
            'name', 'background_color', 'background_prompt', 'background_image_url',
            'background_seed', 'background_blur_mode', 'background_blur_radius',
            'apparel_size', 'apparel_prompt', 'vm_model', 'vm_scene', 'vm_pose',
            'shadow', 'shadow_softness', 'shadow_intensity', 'shadow_spread',
            'shadow_direction', 'shadow_pose', 'text_removal', 'beautify',
            'outline_color', 'outline_width', 'image_width', 'image_height',
            'padding', 'reference_box', 'dpi', 'input_rotation',
            'trim_top', 'trim_bottom',
        ], null) + array_fill_keys([
            'remove_background', 'ironing', 'lighting', 'upscale', 'expand', 'uncrop',
            'rotate_wide_only',
        ], false);

        // Blurring keeps the original scene, so it is the one mode that is not
        // a kind of background removal.
        $removeBackground = $validated['background_mode'] !== 'blur'
            && (bool) $validated['remove_background'];

        $hasSize  = filled($validated['image_width']) && filled($validated['image_height']);
        $apparel  = $validated['apparel_mode'];
        $rotation = (string) ($validated['input_rotation'] ?: '');

        $edits = [
            /*
             * Straightening. "Only the wide ones" is a quarter-turn idea — a
             * half turn leaves the shape alone, so which photos are landscape
             * says nothing about which ones are upside down. The form hides the
             * tickbox for 180°, but a hidden checkbox still posts, so drop it
             * here rather than let a stale tick quietly skip portrait photos.
             */
            'input_rotation'   => $rotation ?: null,
            'rotate_wide_only' => in_array($rotation, ['right', 'left'], true)
                && (bool) $validated['rotate_wide_only'],
            'trim_top'         => filled($validated['trim_top'])    ? (float) $validated['trim_top']    : null,
            'trim_bottom'      => filled($validated['trim_bottom']) ? (float) $validated['trim_bottom'] : null,

            // Background
            'remove_background'      => $removeBackground,
            'background_mode'        => $validated['background_mode'],
            'background_color'       => $validated['background_mode'] === 'custom'
                ? (ltrim((string) $validated['background_color'], '#') ?: 'FFFFFF')
                : ($validated['background_mode'] === 'white' ? 'FFFFFF' : null),
            'background_prompt'      => $validated['background_prompt'] ?: null,
            'background_image_url'   => $validated['background_image_url'] ?: null,
            'background_seed'        => filled($validated['background_seed']) ? (int) $validated['background_seed'] : null,
            'background_blur_mode'   => $validated['background_blur_mode'] ?: null,
            'background_blur_radius' => filled($validated['background_blur_radius']) ? (float) $validated['background_blur_radius'] : null,

            // Clothing
            'ghost_mannequin' => $apparel === 'ghost_mannequin',
            'flat_lay'        => $apparel === 'flat_lay',
            'virtual_model'   => $apparel === 'virtual_model',
            'apparel_size'    => $apparel !== 'none' ? $validated['apparel_size'] : null,
            'apparel_prompt'  => $apparel !== 'none' ? ($validated['apparel_prompt'] ?: null) : null,
            'vm_model'        => $apparel === 'virtual_model' ? $validated['vm_model'] : null,
            'vm_scene'        => $apparel === 'virtual_model' ? $validated['vm_scene'] : null,
            'vm_pose'         => $apparel === 'virtual_model' ? $validated['vm_pose'] : null,
            'ironing'         => (bool) $validated['ironing'],

            // Shadow
            'shadow'           => $validated['shadow'] ?: null,
            'shadow_softness'  => filled($validated['shadow_softness'])  ? (float) $validated['shadow_softness']  : null,
            'shadow_intensity' => filled($validated['shadow_intensity']) ? (float) $validated['shadow_intensity'] : null,
            'shadow_spread'    => $validated['shadow_spread'] ?: null,
            'shadow_direction' => $validated['shadow_direction'] ?: null,
            'shadow_pose'      => $validated['shadow_pose'] ?: null,

            // Finishing
            'text_removal'  => $validated['text_removal'] ?: null,
            'beautify'      => $validated['beautify'] ?: null,
            'lighting'      => (bool) $validated['lighting'],
            'upscale'       => (bool) $validated['upscale'],
            'expand'        => (bool) $validated['expand'],
            'uncrop'        => (bool) $validated['uncrop'],
            'outline_color' => $validated['outline_color'] ? ltrim((string) $validated['outline_color'], '#') : null,
            'outline_width' => filled($validated['outline_width']) ? (float) $validated['outline_width'] : null,

            // Output
            'width'         => $hasSize ? (int) $validated['image_width']  : null,
            'height'        => $hasSize ? (int) $validated['image_height'] : null,
            'padding'       => filled($validated['padding']) ? (float) $validated['padding'] : null,
            'h_align'       => $validated['h_align'],
            'v_align'       => $validated['v_align'],
            'scaling'       => $validated['scaling'],
            'reference_box' => $validated['reference_box'] ?: null,
            'dpi'           => filled($validated['dpi']) ? (int) $validated['dpi'] : null,
        ];

        $session = PhotoEditSession::create([
            'user_id'       => auth()->id(),
            'store_id'      => Store::getActive()?->id,
            'name'          => $validated['name'] ?: 'Edit ' . now()->format('Y-m-d H:i'),
            'onedrive_link' => $validated['onedrive_link'],
            'matching_mode' => $validated['matching_mode'],
            'edits'         => $edits,
            'status'        => 'processing',
            'scan_status'   => 'pending',
        ]);

        ScanPhotoEditFolderJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('photo-editor.show', $session)
            ->with('info', 'Scanning your OneDrive folder — editing starts as soon as the images are found.');
    }

    /**
     * Progress + results, polled by the review page while editing runs.
     */
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
            'id'            => $i->id,
            'filename'      => $i->filename,
            'sku'           => $i->sku_detected,
            'status'        => $i->status,
            'status_label'  => $i->statusLabel(),
            'status_color'  => $i->statusColor(),
            'selected'      => (bool) $i->selected,
            'pushable'      => $i->isPushable(),
            'edited_kb'     => $i->edited_size_kb,
            'original_kb'   => $i->original_size_kb,
            'product_title' => $i->product_title,
            'error'         => $i->error_message,
            'before_url'    => $i->original_thumb_path ? route('photo-editor.preview', [$session, $i, 'before']) : null,
            'after_url'     => $i->edited_thumb_path   ? route('photo-editor.preview', [$session, $i, 'after'])  : null,
            'full_url'      => $i->edited_path         ? route('photo-editor.preview', [$session, $i, 'full'])   : null,
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
