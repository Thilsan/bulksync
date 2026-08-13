<?php

namespace App\Http\Controllers;

use App\Jobs\PushEditedPhotoJob;
use App\Jobs\ScanPhotoEditFolderJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\Store;
use App\Services\PhotoroomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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

            'remove_background' => ['nullable', 'boolean'],
            'background_mode'   => ['required', 'in:transparent,white,custom'],
            'background_color'  => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],

            'apparel_mode'  => ['required', 'in:none,ghost_mannequin,flat_lay'],
            'shadow'        => ['nullable', 'in:ai.soft,ai.hard,ai.floating'],
            'text_removal'  => ['nullable', 'in:ai.artificial,ai.natural,ai.all'],
            'lighting'      => ['nullable', 'boolean'],
            'upscale'       => ['nullable', 'boolean'],

            'image_width'  => ['nullable', 'integer', 'min:100', 'max:5000'],
            'image_height' => ['nullable', 'integer', 'min:100', 'max:5000'],
            'padding'      => ['nullable', 'numeric', 'min:0', 'max:0.49'],
            'h_align'      => ['required', 'in:left,center,right'],
            'v_align'      => ['required', 'in:top,center,bottom'],
            'scaling'      => ['required', 'in:fit,fill'],
        ], [
            'background_color.regex' => 'The background colour must be a 6-digit hex value, e.g. #F5F5F5.',
        ]);

        /*
         * A validated field that was never submitted is absent from the array
         * rather than null — an unticked checkbox, a shadow nobody chose. Fill
         * the optional keys in first, so reading them below cannot fail on a
         * form that simply left something out.
         */
        $validated += [
            'name'              => null,
            'remove_background' => false,
            'background_color'  => null,
            'shadow'            => null,
            'text_removal'      => null,
            'lighting'          => false,
            'upscale'           => false,
            'image_width'       => null,
            'image_height'      => null,
            'padding'           => null,
        ];

        $removeBackground = (bool) $validated['remove_background'];

        // The colour is only meaningful when there is a cutout to place on it.
        $backgroundColor = match ($validated['background_mode']) {
            'white'  => 'FFFFFF',
            'custom' => ltrim((string) ($validated['background_color'] ?? ''), '#') ?: 'FFFFFF',
            default  => null, // transparent
        };

        $hasSize = filled($validated['image_width']) && filled($validated['image_height']);

        $edits = [
            'remove_background' => $removeBackground,
            'background_color'  => $removeBackground ? $backgroundColor : null,
            'ghost_mannequin'   => $validated['apparel_mode'] === 'ghost_mannequin',
            'flat_lay'          => $validated['apparel_mode'] === 'flat_lay',
            'shadow'            => $validated['shadow'] ?: null,
            'text_removal'      => $validated['text_removal'] ?: null,
            'lighting'          => (bool) ($validated['lighting'] ?? false),
            'upscale'           => (bool) ($validated['upscale'] ?? false),
            'width'             => $hasSize ? (int) $validated['image_width']  : null,
            'height'            => $hasSize ? (int) $validated['image_height'] : null,
            'padding'           => filled($validated['padding']) ? (float) $validated['padding'] : null,
            'h_align'           => $validated['h_align'],
            'v_align'           => $validated['v_align'],
            'scaling'           => $validated['scaling'],
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
