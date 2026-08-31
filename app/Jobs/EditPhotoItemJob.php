<?php

namespace App\Jobs;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\ImageProcessingService;
use App\Services\StandEraseCompositor;
use App\Services\OneDriveService;
use App\Services\PhotoroomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch one image from OneDrive, run it through Photoroom, and keep the result
 * on disk until somebody decides whether it goes to Shopify.
 */
class EditPhotoItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // ghost mannequin generation is slow
    public int $tries   = 2;
    public int $backoff = 30;

    /**
     * The ceiling a finished product image has to come in under.
     *
     * Not a compression target — a limit that is rarely reached. Photoroom
     * hands back a lossless PNG and the JPEG is written here at the highest
     * quality that fits, which for a 2000 square of a product on white means
     * quality 100 at roughly 770 KB. The measured difference from the PNG at
     * that setting is an average of 0.02 of 255 shades per pixel, so the
     * megabyte buys a file a third of the size and nothing visible lost.
     */
    private const MAX_OUTPUT_BYTES = 1_000_000;

    public function __construct(
        public readonly int $itemId,
    ) {}

    public function handle(
        OneDriveService        $oneDrive,
        ImageProcessingService $imageService,
        PhotoroomService       $photoroom,
        GeminiService          $gemini,
        // Appended rather than slotted in: the tests call handle() positionally,
        // and a new dependency is not a reason to rewrite their arguments.
        ?StandEraseCompositor  $compositor = null,
    ): void {
        $compositor ??= new StandEraseCompositor();

        $item = PhotoEditItem::find($this->itemId);

        // Terminal states only. 'editing' means a previous attempt died partway
        // through — a worker restart, a timeout kill — and the retry has to be
        // allowed to finish the job, or the item is stuck forever.
        if (!$item || in_array($item->status, ['edited', 'pushed', 'skipped'], true)) {
            return;
        }

        $session = PhotoEditSession::find($item->photo_edit_session_id);

        if (!$session) {
            return;
        }

        $item->update(['status' => 'editing']);

        if ($session->user_id && ($user = User::find($session->user_id))) {
            $oneDrive->setUser($user);
        }

        // Settings belong to the SKU group, not the run: a folder of dresses
        // and a folder of watches were configured separately.
        $edits = $item->resolvedEdits();

        try {
            $raw = $oneDrive->downloadFileById(
                $item->onedrive_drive_id,
                $item->onedrive_item_id,
                $item->onedrive_download_url ?? '',
            );

            // Straighten first, before anything decodes the pixels. Studio
            // cameras record rotation as an EXIF flag rather than rotating the
            // image, and every step after this one would drop that flag and
            // leave the garment lying on its side.
            $raw = $imageService->normalizeOrientation($raw);

            // EXIF only fixes what the camera flagged. A garment shot lying
            // across the frame carries no flag, so the operator's own answer is
            // applied here — before the "before" thumbnail, so the review
            // screen compares against the image Photoroom was actually given.
            $raw = $imageService->rotate(
                $raw,
                (string) ($edits['input_rotation'] ?? ''),
                !empty($edits['rotate_wide_only']),
            );

            // Trimming comes after the turn on purpose: "the bottom" has to mean
            // the bottom of the upright photo the operator was picturing when
            // they set it, not whichever edge happened to be down in the file.
            $raw = $imageService->trimEdges(
                $raw,
                (float) ($edits['trim_top'] ?? 0),
                (float) ($edits['trim_bottom'] ?? 0),
            );

            // Ghost mannequin / flat lay / virtual model regenerate the
            // garment from scratch — useful for the "floating garment" look,
            // but generative reconstruction carries no guarantee of matching
            // the original photo's color or orientation. That is why every
            // such item is downgraded to a plain cutout by default.
            //
            // Putting a garment on a model is the exception. There is no
            // real-pixel version of a person who was never photographed, so
            // downgrading it would not produce a safer image — it would
            // produce no image at all. Generation is the feature there, and
            // the operator picked it knowingly.
            //
            // Classification is only spent on sessions that asked for a redraw
            // mode in the first place, to know whether a mannequin is actually
            // in frame to erase. A classification failure fails open: the item
            // still gets the plain-cutout fallback rather than derailing over
            // an unrelated API hiccup.
            $classification = null;
            $onModel        = !empty($edits['virtual_model']);

            if ($photoroom->generatesOwnCanvas($edits) && !$onModel) {
                try {
                    $classification = $gemini->classifyGarmentView($raw);
                } catch (\Throwable $e) {
                    Log::warning("EditPhotoItemJob item {$this->itemId} classification failed: " . $e->getMessage());
                }
            }

            $itemEdits   = $edits;
            $appliedMode = 'none';

            /*
             * Erase the stand surgically, if that is what was asked for.
             *
             * Deliberately outside the block below, which only runs when an AI
             * treatment is generating the canvas. This is the opposite of that:
             * nothing generates a canvas, the photograph is kept, and the
             * generative pass is used on a strip of it and nowhere else.
             *
             * No classifier is consulted either. Somebody chose this treatment
             * looking at the photo; a second opinion could only overrule them.
             */
            if (!empty($edits['surgical_erase']) && !$onModel) {
                try {
                    $strip = $compositor->topStrip($raw, (float) ($edits['erase_zone'] ?? 0.40));

                    $erased = $photoroom->removeMannequin(
                        $strip['bytes'],
                        $item->filename,
                        filled($edits['edit_seed'] ?? null) ? (int) $edits['edit_seed'] : null,
                    );

                    $raw         = $compositor->blend($raw, $erased, $strip['height']);
                    $appliedMode = 'stand_erased';

                    unset($erased, $strip);
                } catch (\Throwable $e) {
                    /*
                     * The photograph is still intact and still worth having, so
                     * the run continues with the stand in shot rather than
                     * failing. Logged loudly: a stand nobody removed is easy to
                     * miss on a review screen of three hundred.
                     */
                    Log::error("EditPhotoItemJob item {$this->itemId} surgical erase failed: " . $e->getMessage());
                }
            }

            if ($photoroom->generatesOwnCanvas($edits)) {
                if ($onModel) {
                    // Photoroom builds the whole scene, mannequin and all — a
                    // separate erase pass would only be a wasted request.
                    $appliedMode = 'on_model';
                } else {
                    $itemEdits['ghost_mannequin']   = false;
                    $itemEdits['flat_lay']          = false;
                    $itemEdits['virtual_model']     = false;
                    $itemEdits['remove_background'] = true;

                    // A visible mannequin is erased via a generative pass
                    // regardless of which side of the garment faces the camera
                    // — cutting the background alone would otherwise leave the
                    // stand in frame. Text-guided segmentation can do this
                    // inside the single cutout request instead; when the
                    // session supplies a segmentation prompt, that is used and
                    // this extra request is skipped.
                    $needsErase = $classification && !empty($classification['mannequin_visible']);

                    if ($needsErase && empty($edits['segmentation_prompt'])) {
                        try {
                            $before = $this->describeSize($raw);

                            $raw         = $photoroom->removeMannequin(
                                $raw,
                                $item->filename,
                                filled($edits['edit_seed'] ?? null) ? (int) $edits['edit_seed'] : null,
                            );
                            $appliedMode = 'mannequin_removed';

                            /*
                             * The generative pass hands back its own canvas, and
                             * if that is smaller than what went in, everything
                             * downstream is working from fewer pixels — the
                             * finished 2000 square is then an enlargement of it.
                             * Worth knowing, because a soft result looks the
                             * same whether it was redrawn softly or upscaled.
                             */
                            $after = $this->describeSize($raw);

                            if ($before !== $after) {
                                Log::warning('Photoroom erase changed the resolution', [
                                    'item' => $this->itemId,
                                    'in'   => $before,
                                    'out'  => $after,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::warning("EditPhotoItemJob item {$this->itemId} mannequin removal failed: " . $e->getMessage());
                        }
                    } elseif ($needsErase) {
                        $appliedMode = 'segmented';
                    }
                }
            }

            // Photoroom refuses anything over 30 MB or 5000 px on its widest
            // side. Shrinking here costs one local decode; finding out from the
            // API costs a round trip and the whole upload.
            $input = $this->fitForPhotoroom($raw, $imageService);
            unset($raw);

            $dir = $session->absoluteStorageDir();

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create storage directory for session {$session->id}.");
            }

            // "Before" thumbnail, written before the API call so a failed edit
            // still shows what went in.
            $beforeRel = $session->storageDir() . "/{$item->id}-before.jpg";
            file_put_contents(storage_path('app/' . $beforeRel), $imageService->thumbnail($input, 420));

            $edited = $photoroom->edit($input, $itemEdits, $item->filename);
            unset($input);

            $format = $photoroom->outputFormat($itemEdits);
            $isJpeg = $format === 'jpg';

            /*
             * What arrived is lossless — Photoroom was asked for PNG precisely
             * so its own fixed quality-80 JPEG never touched the picture. The
             * JPEG is made here instead, at the highest quality that fits the
             * ceiling, which on a product against a plain background is usually
             * the top of the scale.
             *
             * A transparent cutout is kept as it came: JPEG cannot hold an
             * alpha channel, so it only needs Shopify's megapixel ceiling
             * enforced in the alpha-safe way.
             */
            $edited = $isJpeg
                ? $imageService->toJpegUnderLimit($edited, self::MAX_OUTPUT_BYTES)
                : $imageService->capPixelCountPreservingAlpha($edited, $format);

            $ext          = $format;
            $editedRel    = $session->storageDir() . "/{$item->id}-after.{$ext}";
            $thumbRel     = $session->storageDir() . "/{$item->id}-after-thumb.{$ext}";

            file_put_contents(storage_path('app/' . $editedRel), $edited);
            file_put_contents(storage_path('app/' . $thumbRel), $imageService->thumbnail($edited, 420, !$isJpeg));

            $item->update([
                'status'               => 'edited',
                'original_thumb_path'  => $beforeRel,
                'edited_path'          => $editedRel,
                'edited_thumb_path'    => $thumbRel,
                'edited_size_kb'       => (int) round(strlen($edited) / 1024),
                'error_message'        => null,
                'view_type'            => $classification['view_type'] ?? null,
                'mannequin_visible'    => $classification['mannequin_visible'] ?? null,
                'apparel_mode_applied' => $appliedMode,

                // Read straight after the edit it belongs to — the service
                // keeps only the most recent call's score.
                'uncertainty_score'    => $photoroom->lastUncertaintyScore(),
            ]);

        } catch (\Throwable $e) {
            Log::error("EditPhotoItemJob item {$this->itemId} failed: " . $e->getMessage());

            $item->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                $this->syncSessionCounts($item->photo_edit_session_id);
                throw $e;
            }
        }

        $this->syncSessionCounts($item->photo_edit_session_id);
    }

    public function failed(\Throwable $e): void
    {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item) {
            return;
        }

        $item->update([
            'status'        => 'failed',
            'error_message' => 'Max retries reached: ' . $e->getMessage(),
        ]);

        $this->syncSessionCounts($item->photo_edit_session_id);
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * Bring an image inside Photoroom's input limits. Never upscales, and
     * returns the original bytes untouched when it already fits.
     */
    /** "1628x2022", or "unreadable" — for log lines, not for decisions. */
    private function describeSize(string $imageContent): string
    {
        $info = @getimagesizefromstring($imageContent);

        return $info ? $info[0] . 'x' . $info[1] : 'unreadable';
    }

    private function fitForPhotoroom(string $content, ImageProcessingService $imageService): string
    {
        $info    = @getimagesizefromstring($content);
        $tooWide = $info && max((int) $info[0], (int) $info[1]) > PhotoroomService::MAX_INPUT_EDGE;

        if (!$tooWide && strlen($content) <= PhotoroomService::MAX_INPUT_BYTES) {
            return $content;
        }

        return $imageService->scaleDownForAnalysis(
            $content,
            PhotoroomService::MAX_INPUT_EDGE,
            PhotoroomService::MAX_INPUT_BYTES,
        );
    }

    private function syncSessionCounts(int $sessionId): void
    {
        $stillWorking = PhotoEditItem::where('photo_edit_session_id', $sessionId)
            ->whereIn('status', ['pending', 'editing'])
            ->exists();

        PhotoEditSession::where('id', $sessionId)->update([
            'edited_files' => PhotoEditItem::where('photo_edit_session_id', $sessionId)
                ->whereIn('status', ['edited', 'pushing', 'pushed'])->count(),
            'failed_files' => PhotoEditItem::where('photo_edit_session_id', $sessionId)
                ->whereIn('status', ['failed', 'skipped'])->count(),
            'status'       => $stillWorking ? 'processing' : 'completed',
        ]);
    }
}
