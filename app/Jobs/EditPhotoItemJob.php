<?php

namespace App\Jobs;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\ImageProcessingService;
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
    ): void {

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

            if ($photoroom->generatesOwnCanvas($edits) && !$onModel && !$item->keep_background) {
                try {
                    $classification = $gemini->classifyGarmentView($raw);
                } catch (\Throwable $e) {
                    Log::warning("EditPhotoItemJob item {$this->itemId} classification failed: " . $e->getMessage());
                }
            }

            $itemEdits   = $edits;
            $appliedMode = 'none';

            /*
             * This photo is here for the framing, not the cutout. Its
             * background is the point — a detail shot, or a frame the
             * photographer composed — so it goes to Photoroom to be put on the
             * same canvas at the same size as its siblings, with the erase
             * switched off.
             *
             * It overrides the redraw modes too. Ghost mannequin, flat lay and
             * virtual model all build a new canvas from scratch, which cannot
             * mean anything for a photo whose whole point is the background it
             * already has.
             */
            if ($item->keep_background) {
                $itemEdits['remove_background'] = false;
                $itemEdits['ghost_mannequin']   = false;
                $itemEdits['flat_lay']          = false;
                $itemEdits['virtual_model']     = false;

                $appliedMode = 'kept_background';
            } elseif ($photoroom->generatesOwnCanvas($edits)) {
                if ($onModel) {
                    // Photoroom builds the whole scene, mannequin and all — a
                    // separate erase pass would only be a wasted request.
                    $appliedMode = 'on_model';
                } else {
                    /*
                     * Three questions decide the route, so all three are
                     * settled before any of them is acted on: is a stand
                     * actually in shot, did anyone name the product, and was a
                     * redraw asked for.
                     */
                    $standVisible = $classification && !empty($classification['mannequin_visible']);
                    $named        = filled($edits['segmentation_prompt'] ?? null);
                    $wantsRedraw  = !empty($edits['ghost_mannequin']);

                    if ($wantsRedraw && $standVisible && !$named) {
                        /*
                         * Photoroom's own Ghost Mannequin. This used to be
                         * switched off here and replaced with a generic
                         * editWithAI pass, on the grounds that generative
                         * reconstruction could not be trusted with a garment's
                         * colour or orientation — a fair call, made against the
                         * wrong feature.
                         *
                         * Side by side on one shirt: editWithAI reinvented an
                         * Aigner horseshoe monogram as rings, at 4% of the
                         * original's print detail. Ghost Mannequin reproduced
                         * the horseshoes. One is apparel-aware; the other is a
                         * general image editor being asked to understand a
                         * garment.
                         *
                         * The size is named rather than left open, because
                         * Photoroom's app exposes quality tiers that turn out to
                         * be resolutions — 1024, 2048, 4096 — and 1024 is the
                         * tier that destroys a print, at 7% of the original's
                         * detail.
                         */
                        $itemEdits['apparel_size']   ??= 'SQUARE_HD';
                        $itemEdits['apparel_prompt']   = filled($edits['apparel_prompt'] ?? null)
                            ? $edits['apparel_prompt']
                            : PhotoroomService::GHOST_MANNEQUIN_PROMPT;
                        $itemEdits['remove_background'] = true;

                        $appliedMode = 'ghost_mannequin';
                    } else {
                        /*
                         * Everything else is a plain cutout. Nothing to erase,
                         * or no redraw was asked for, or the product was named —
                         * and naming it is the better route anyway: one request
                         * rather than two, cutting the stand out of the real
                         * photograph rather than redrawing round it.
                         */
                        $itemEdits['ghost_mannequin']   = false;
                        $itemEdits['flat_lay']          = false;
                        $itemEdits['virtual_model']     = false;
                        $itemEdits['remove_background'] = true;

                        if ($standVisible && $named) {
                            $appliedMode = 'segmented';
                        } elseif ($standVisible) {
                            /*
                             * A stand is in shot, nobody named the product, and
                             * no redraw was asked for. The generic erase is all
                             * that is left — and it is the pass that reinvents
                             * prints, so it stays the last resort it always was.
                             */
                            try {
                                $before = $this->describeSize($raw);

                                $raw = $photoroom->removeMannequin(
                                    $raw,
                                    $item->filename,
                                    filled($edits['edit_seed'] ?? null) ? (int) $edits['edit_seed'] : null,
                                );

                                $appliedMode = 'mannequin_removed';

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
                        }
                    }
                }
            }

            // Photoroom refuses anything over 30 MB or 5000 px on its widest
            // side. Shrinking here costs one local decode; finding out from the
            // API costs a round trip and the whole upload.
            /*
             * Trim the empty background first. It is what puts these files over
             * the upscaler's ceiling — a 1146-square of mostly white is 1.3
             * million pixels, and the best model takes a quarter of one — and
             * what leaves the product too small in frame for Photoroom to scale
             * up. Everything after this sees the product rather than the sheet
             * of white it was photographed on.
             */
            $raw = $imageService->cropToSubject($raw);

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

            // Whether to upscale is the operator's call. What is settled here is
            // how: which model can take an input this size, and what it should
            // be upscaled to.
            if (!$photoroom->generatesOwnCanvas($itemEdits)) {
                $itemEdits = $this->tuneUpscale($input, $itemEdits);
            }

            $edited = $photoroom->edit($input, $itemEdits, $item->filename);

            /*
             * A ring that arrived 500 px wide and leaves on a 2000 px canvas has
             * been enlarged four times over, and interpolation makes those new
             * pixels by averaging the old ones — which is exactly what reads as
             * soft. Sharpening restores the edge contrast that averaging
             * flattened. It adds no detail, because nothing can; it stops the
             * detail that survived from looking like less than it is.
             *
             * Measured against what actually went out rather than against the
             * preset, so a photograph that already had the pixels is left alone.
             * It has to happen here, while the bytes that were sent are still in
             * hand — a line later they are released.
             */
            /*
             * Photoroom framed it; this makes the framing exact. The same
             * settings put one ring at 59.7% of its canvas and another at
             * 65.5%, because how far it will scale a subject depends on the
             * picture — and a measured standard that lands within six points is
             * not a standard. Arithmetic settles it where negotiation cannot.
             */
            if (!empty($itemEdits['framing_preset'])
                && !$photoroom->generatesOwnCanvas($itemEdits)
                && !empty($itemEdits['width'])) {
                $edited = $imageService->frameToStandard(
                    $edited,
                    (int) $itemEdits['width'],
                    (float) ($itemEdits['padding'] ?? 0.10),
                    isset($itemEdits['padding_bottom']) ? (float) $itemEdits['padding_bottom'] : null,
                    (string) ($itemEdits['v_align'] ?? 'center'),
                );
            }

            if ($imageService->wasEnlarged($input, $edited)) {
                $edited = $imageService->sharpenAfterEnlargement($edited);
            }

            unset($input);

            /*
             * Which tier Photoroom actually gave us. Its app calls 1024, 2048
             * and 4096 standard, advanced and premium; the API documents only
             * "HD" and offers no quality parameter, so the only way to know is
             * to measure what arrives.
             */
            if ($appliedMode === 'ghost_mannequin') {
                Log::info('Ghost mannequin resolution', [
                    'item' => $this->itemId,
                    'size' => $this->describeSize($edited),
                ]);
            }


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

    /**
     * Settle how an upscale should run, for a run that asked for one.
     *
     * Not whether. Turning it on automatically for anything smaller than the
     * canvas was tried and taken back out: it does not do what it appears to
     * promise. Photoroom's fit shrinks a subject onto the canvas but will not
     * enlarge one beyond its own pixels, so a photograph whose product is small
     * in frame comes back small however much the picture around it is
     * enlarged — and the operator, having ticked nothing, has no way to know
     * why. An enhancement that quietly costs quality on every small photo and
     * fixes the one thing people expect it to fix is worse than a checkbox.
     *
     * What is still decided here is which model to ask for. The two are not
     * interchangeable: ai.slow is the better picture and refuses anything over
     * a quarter of a megapixel, ai.fast takes four times that, and above the
     * larger ceiling there is no upscaling to be had at all — so a request that
     * cannot succeed is dropped rather than spent. The target resolution is
     * pinned to the canvas, because left open the model picks its own factor
     * and a mixed catalogue comes out at mixed sizes.
     */
    private function tuneUpscale(string $content, array $edits): array
    {
        $canvas = max((int) ($edits['width'] ?? 0), (int) ($edits['height'] ?? 0));
        $info   = @getimagesizefromstring($content);

        if (!$info) {
            return $edits;
        }

        $pixels = (int) $info[0] * (int) $info[1];

        if ($pixels > PhotoroomService::UPSCALE_MAX_PIXELS[PhotoroomService::UPSCALE_FAST]) {
            // Too big for any model. An upscale asked for here would be a 400,
            // so it is dropped rather than spent.
            unset($edits['upscale'], $edits['upscale_mode'], $edits['upscale_resolution']);

            return $edits;
        }

        /*
         * Turned on by the picture, not by a checkbox — but only now that the
         * background has been trimmed, which is what makes it work at all.
         *
         * It was tried before the crop existed and taken back out, rightly: the
         * two largest supplier files were over the ceiling so the upscale never
         * ran, and on the one that did the product was still too small in frame
         * for Photoroom to scale up. Cropped, both objections go: the pixel
         * count falls inside the quality model and the product fills its frame.
         */
        if (empty($edits['upscale'])) {
            if ($canvas <= 0 || max((int) $info[0], (int) $info[1]) >= $canvas) {
                return $edits;
            }

            $edits['upscale'] = true;
        }

        $edits['upscale_mode'] ??= $pixels <= PhotoroomService::UPSCALE_MAX_PIXELS[PhotoroomService::UPSCALE_SLOW]
            ? PhotoroomService::UPSCALE_SLOW
            : PhotoroomService::UPSCALE_FAST;

        if ($canvas > 0) {
            $edits['upscale_resolution'] ??= $canvas;
        }

        return $edits;
    }

    private function fitForPhotoroom(string $content, ImageProcessingService $imageService): string
    {
        // Depth is not a size problem, so it is settled before the size checks
        // below — those return the original bytes untouched whenever the picture
        // already fits, which is exactly how a small 10-bit AVIF reached the API
        // unaltered and came back rejected.
        $content = $imageService->capBitDepth($content);

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
