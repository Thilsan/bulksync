<?php

namespace App\Jobs;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\GhostCompositeService;
use App\Services\GhostPrintTransplantService;
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
        // Kept in the signature rather than removed: the container resolves it,
        // several tests and the photoroom:ghost-transplant command still use the
        // service, and dropping the parameter would rewrite every caller for no
        // behavioural gain. See the composite block below for why it is no
        // longer called from here.
        GhostPrintTransplantService $transplant,
        GhostCompositeService $composite,
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

            /*
             * The price tag comes off before anything else looks at the photo.
             *
             * Before the cutout, because a swing ticket is otherwise measured
             * as part of the subject — the framing then sizes garment-plus-
             * ticket to the canvas, so the garment lands smaller and lower than
             * the baseline and the space the ticket occupied stays in frame.
             *
             * Its own request rather than a field on the edit, for the reason
             * removeMannequin is: Photoroom warns that mixing editWithAI with
             * removeBackground in one call gives unpredictable results.
             *
             * A failure here is not fatal. The tag is a blemish; losing the
             * whole edit over it would be the worse outcome, so it is logged
             * and the photo goes on without it.
             */
            if (!empty($edits['remove_price_tag'])) {
                try {
                    $raw = $photoroom->eraseObject(
                        $raw,
                        PhotoroomService::PRICE_TAG_REMOVAL_PROMPT,
                        $item->filename,
                        filled($edits['edit_seed'] ?? null) ? (int) $edits['edit_seed'] : null,
                    );
                } catch (\Throwable $e) {
                    Log::warning("EditPhotoItemJob item {$this->itemId} price tag removal failed: " . $e->getMessage());
                }
            }

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
            /*
             * Nothing is classified any more, and no second opinion is asked
             * of anything outside Photoroom.
             *
             * Gemini used to read every apparel photo before it was edited —
             * which side was facing, whether a mannequin was in frame, what
             * the product was, what was holding it up — and the routing was
             * built on those answers. It read too many of them wrong to be
             * worth keeping. A gown plainly on a full dress form came back
             * classified as having no mannequin in it at all, so nothing ever
             * tried to remove one; the same dress's two views were labelled
             * front and back on one run and front and front on the next; and
             * the whole apparatus turned one ticked checkbox into an outcome
             * nobody could predict from the outside. Two photos of one SKU,
             * one setting, four different results.
             *
             * Ticking "Remove the stand" is the instruction. It does not need
             * confirming by a second model that is wrong often enough to
             * contradict it, and the operator who ticked it is looking at the
             * photograph anyway.
             *
             * What is lost with it, said plainly rather than discovered later:
             * the front/back badge on the review grid, and the automatic
             * guess at a product noun for a named cutout. The Keep box is
             * still there to type one into by hand, which was always the more
             * reliable half of that feature.
             */
            $onModel = !empty($edits['virtual_model']);

            $itemEdits   = $edits;
            $appliedMode = 'none';

            // Set when a redraw changed the garment rather than merely lifting
            // it off the stand, so the screen can say which way it went wrong.
            $redrawNote  = null;

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
                    $rawInfo = @getimagesizefromstring($raw);

                    [$appliedMode, $itemEdits] = $this->chooseApparelRoute(
                        $itemEdits,
                        (int) ($rawInfo[0] ?? 0),
                        (int) ($rawInfo[1] ?? 0),
                    );

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
            /*
             * A close-up is the same photograph, cropped to the pendant before
             * anything else happens to it. Everything after this — the upscale,
             * the framing, the sharpening — then treats the pendant as the
             * product, which is the point: at catalogue framing a pendant is a
             * fiftieth of the picture, and reconstructing it from a crop is the
             * only way to get a second image worth looking at.
             */
            if ($item->isCloseup()) {
                $pendant = $imageService->cropToPendant($raw);

                if ($pendant === null) {
                    $item->update([
                        'status'        => 'failed',
                        'error_message' => 'No pendant to photograph — this necklace is chain all the way down.',
                    ]);

                    return;
                }

                $raw = $pendant;

                /*
                 * The necklace standard hangs the product from the top of the
                 * frame, which is right for a necklace and wrong for the
                 * pendant off it. A close-up is a product on its own and sits
                 * in the middle like every other one.
                 */
                unset($itemEdits['padding_top'], $itemEdits['padding_bottom']);
                $itemEdits['v_align'] = 'center';
                $itemEdits['padding'] = 0.12;
            }

            /*
             * A raised trolley handle, cut off before anything measures the
             * product. Left on, it is half the picture: the framing sizes the
             * case against a chrome pole and every suitcase comes out small.
             *
             * Done for every photo of a SKU that has a case height typed against
             * it, not only the ones ticked by hand, because a raised handle and
             * a typed size cannot both be honoured and the operator should not
             * have to know that. A handle runs about 0.87x its case, so a 55 cm
             * case asked to fill 55% of the canvas needs 103% of it with the
             * handle up. Rather than slice the handle, frameToStandard shrinks
             * the whole product — silently, and only on the photos that have a
             * handle in them. Measured, a handle-up photo cannot pass 47.8%
             * however large a case is typed, so ticking one photo and leaving
             * its siblings produced exactly what was reported: the cropped one
             * at 55% beside three at 47.8%.
             *
             * Safe to apply unasked because cropAboveBody refuses anything that
             * is not a distinctly thin part standing more than a tenth of the
             * product above its body. A case photographed handle-down, a lid, a
             * clasp, a detail shot — all come back null and are left alone.
             */
            $sized = filled($itemEdits['case_height_cm'] ?? null);

            if ($item->remove_handle || $sized) {
                $withoutHandle = $imageService->cropAboveBody($raw);

                if ($withoutHandle === null) {
                    Log::info('EditPhotoItemJob: nothing above the body to crop', ['item' => $this->itemId]);
                } else {
                    $raw = $withoutHandle;

                    if (!$item->remove_handle) {
                        Log::info('EditPhotoItemJob: handle cropped to reach the typed case height', [
                            'item' => $this->itemId,
                            'cm'   => $itemEdits['case_height_cm'],
                        ]);
                    }
                }
            }

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
             * Ironing has no prompt of its own to be told "keep the colour
             * and the material" — Photoroom's API documents a single field
             * for it, ironing.mode, and nothing else. Every other generative
             * option here that changes real pixels (ghost mannequin, the
             * price-tag erase pass) gets one because it accepts a prompt;
             * ironing does not accept text at all, so the only thing left is
             * to measure what came back rather than trust it.
             *
             * Checked against $input, not the original download — $input is
             * exactly what was sent to Photoroom, so this is the same
             * before-and-after pair the request itself saw, not before-and-
             * after some other step's changes too.
             *
             * Not fatal, the same way a failed price-tag erase is not: the
             * cutout is still real and still usable. Retried once without
             * ironing rather than discarded outright, because the wrinkle the
             * operator asked to have pressed out is a real thing they wanted
             * and losing it over a threshold that is a judgement call, not a
             * measurement, would be the more surprising outcome of the two.
             */
            if (!empty($itemEdits['ironing'])) {
                $ironedShift = $imageService->colourShift($input, $edited);

                if ($ironedShift !== null && $ironedShift > self::MAX_IRONING_COLOUR_SHIFT) {
                    Log::warning('Ironing shifted the subject colour past the limit, retrying without it', [
                        'item'  => $this->itemId,
                        'shift' => $ironedShift,
                    ]);

                    try {
                        $unironed = $itemEdits;
                        $unironed['ironing'] = false;

                        $edited = $photoroom->edit($input, $unironed, $item->filename);

                        $redrawNote = sprintf(
                            'Ironing was skipped: pressing the garment shifted its own colour by %.1f%% '
                            . '(limit %.0f%%), which is more than steaming out a wrinkle should cost. '
                            . 'The photo was kept as shot, creases and all.',
                            $ironedShift * 100,
                            self::MAX_IRONING_COLOUR_SHIFT * 100,
                        );
                    } catch (\Throwable $e) {
                        Log::warning(
                            "EditPhotoItemJob item {$this->itemId} un-ironed retry failed: " . $e->getMessage()
                        );
                    }
                }
            }

            /*
             * A cutout that kept the whole frame is not a cutout.
             *
             * This is the failure that arrives looking like a success — the
             * file is smaller, the status reads ready, the badge says the
             * mannequin was segmented out, and the picture is a mannequin
             * standing in a studio. Nothing after this point looks at the
             * pixels, so nothing after this point can tell, and it goes to
             * Shopify on a real product.
             *
             * Failed rather than fixed, because there is no honest fix here.
             * The request was made and the credit is spent; what is left to
             * decide is whether a studio photograph is published, and that is
             * not a decision to take silently. The message names the likely
             * cause, since the operator is the one who can act on it.
             *
             * Checked wherever the product was named, however it was named.
             *
             * It used to run only on the app's own guesses, on the reasoning
             * that a word typed by somebody looking at the photograph could be
             * trusted. That reasoning was wrong in a way the pictures showed: a
             * typed "the skirt" came back as a mannequin in a studio, marked
             * ready, and nothing looked. Whether the cutout worked is a fact
             * about the pixels; who chose the word has no bearing on it.
             *
             * A cutout that ran on Photoroom's own matting is still left alone —
             * there is no prompt to blame and nothing to retry without.
             */
            if (filled($itemEdits['segmentation_prompt'] ?? null) && $imageService->looksUncut($edited)) {
                $guess = (string) $itemEdits['segmentation_prompt'];

                Log::warning('EditPhotoItemJob: the named cutout kept the whole frame', [
                    'item'  => $this->itemId,
                    'named' => $guess,
                ]);

                /*
                 * Failed rather than retried without the word.
                 *
                 * There used to be a retry here, for the case where the app
                 * had guessed the word itself: drop the guess, fall back to
                 * Photoroom's own matting, spend a credit finding out. Nothing
                 * guesses a product noun any more — the classifier that did it
                 * is gone — so every word in this box was typed by somebody
                 * looking at the photograph, and they have already made the
                 * judgement a retry would be overruling. Failing and saying
                 * why leaves the next move with the person who can make it.
                 */
                $item->update([
                    'status'        => 'failed',
                    'error_message' => "Nothing was cut out — \"{$guess}\" found no product in this photo, "
                        . 'so the whole studio came back. Try clearing the "Keep" box to use Photoroom\'s own '
                        . 'background removal, or a different description.',
                ]);

                $this->syncSessionCounts($session->id);

                return;
            }

            /*
             * Ghost Mannequin comes back at 1K whatever canvas was asked for —
             * Photoroom's own documentation reserves 2K and 4K for Enterprise
             * plans — and at that size it does not merely soften a print, it
             * draws a different one. Measured on an Aigner tee across all three
             * of their tiers: the horseshoe monogram came back as a generic
             * dotted grid at 1K, and at 4K as a sharp motif that still was not
             * horseshoes. Resolution buys fidelity of rendering, not fidelity to
             * the product, so a higher tier would buy a crisp forgery — on a
             * licensed brand, worse than a soft one.
             *
             * The photograph still holds the real product, so the redraw is
             * used only to the extent it can be shown to agree with it.
             */
            if ($appliedMode === 'ghost_mannequin') {
                /*
                 * The redraw is kept. The stand going is the whole of what was
                 * asked for, and the request for it is the ticked checkbox.
                 *
                 * This used to refuse a redraw the composite could not verify
                 * and fall back to a plain cutout — the photograph with the
                 * stand still standing in it. Defensible on paper and wrong in
                 * practice: the operator ticked "Remove the stand" and was
                 * handed back the stand, on some photos and not others, with
                 * no way to tell in advance which. Two views of one dress,
                 * one setting, one redrawn and one not.
                 *
                 * So the composite is now an improvement, never a veto. Where
                 * the redraw lines up with the photograph it is composited —
                 * the stand gone at full resolution with the real drape,
                 * direction and fabric, which is strictly the best result
                 * available. Where it does not line up, the redraw itself is
                 * kept rather than thrown away, and the reason the composite
                 * gave is put on the item so the operator knows to look at the
                 * print and the cut before pushing.
                 *
                 * What that trades: a recut garment can now reach the review
                 * grid, where before it was refused outright. It reaches it
                 * flagged, in front of somebody who can see it, which is a
                 * better place for that judgement than a rule that could not
                 * tell a recut skirt from a different dress.
                 */
                $verified   = false;
                $keptRedraw = false;

                try {
                    $whole = $composite->composite($input, $edited);

                    $verified = $whole['accepted'];

                    if ($verified) {
                        $edited      = $whole['image'];
                        $appliedMode = 'ghost_photo_kept';
                    } else {
                        /*
                         * $edited already holds the redraw — it is what came
                         * back from Photoroom and what the composite has just
                         * measured — so keeping it is a matter of not
                         * replacing it.
                         */
                        $keptRedraw  = true;
                        $appliedMode = 'ghost_redraw_kept';
                        $redrawNote  = 'The stand was removed by redrawing the garment. ' . $whole['reason']
                            . ' The photograph was not used — check the print, the colour and the drape '
                            . 'before pushing.';
                    }

                    Log::info('Ghost mannequin composite', [
                        'item'     => $this->itemId,
                        'accepted' => $whole['accepted'],
                        'verdict'  => $whole['verdict'],
                        'reason'   => $whole['reason'],
                    ]);
                } catch (\Throwable $e) {
                    /*
                     * The composite could not run at all — a decode failure, a
                     * subject it could not find. The redraw itself is still
                     * what came back from Photoroom and the stand is still
                     * gone in it, so it is kept and said so, rather than the
                     * whole edit being abandoned over a measurement that could
                     * not be taken.
                     */
                    $keptRedraw  = true;
                    $appliedMode = 'ghost_redraw_kept';
                    $redrawNote  = 'The stand was removed by redrawing the garment. The redraw could not be '
                        . 'measured against the photograph, so nothing here can say how closely it matches — '
                        . 'check the print, the colour and the drape before pushing.';

                    Log::warning(
                        "EditPhotoItemJob item {$this->itemId} composite could not run: " . $e->getMessage()
                    );
                }

                unset($verified, $keptRedraw);
            }

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
            /*
             * Ghost Mannequin is let through this gate now; flat lay and
             * virtual model still are not. All three used to be excluded
             * together as "generates its own canvas", true of all three in
             * the sense that Photoroom decides the composition — but only
             * flat lay and virtual model build a scene frameToStandard's
             * subject-box detection was never meant for (a lifestyle
             * background, a person). A Ghost Mannequin result is a plain
             * product on white or transparent, exactly like a cutout, and
             * skipping this step for it only ever meant the redraw came back
             * at whatever pixel size Photoroom's own apparel_size preset
             * produces — a real jacket and t-shirt batch showed what that
             * costs: the front photo (redrawn) and the back photo (erased,
             * which does pass through here) landing at visibly different
             * final dimensions from each other in the same catalogue, one
             * looking sized-down next to the other for no reason a shopper
             * would understand. Framed here the same as everything else, a
             * kept redraw ends the run on the same canvas its own SKU's
             * other photos do.
             */
            if (!empty($itemEdits['framing_preset'])
                && empty($itemEdits['flat_lay'])
                && empty($itemEdits['virtual_model'])
                && !empty($itemEdits['width'])) {
                $edited = $imageService->frameToStandard(
                    $edited,
                    (int) $itemEdits['width'],
                    (float) ($itemEdits['padding'] ?? 0.10),
                    isset($itemEdits['padding_bottom']) ? (float) $itemEdits['padding_bottom'] : null,
                    (string) ($itemEdits['v_align'] ?? 'center'),
                    paddingTop: isset($itemEdits['padding_top']) ? (float) $itemEdits['padding_top'] : null,
                    /*
                     * A typed case height wins over the preset's fixed fill.
                     *
                     * The preset gives every case 0.48 so that one product
                     * cannot appear at two sizes depending on whether its
                     * handle was up — which worked, and made a cabin case and a
                     * large one identical. On luggage that is the wrong trade:
                     * size is the attribute the customer is shopping for.
                     *
                     * Measured off a reference set, a case fills a percentage
                     * of the canvas equal to its height in centimetres, so the
                     * number the operator types is divided by a hundred and
                     * nothing else is needed. It drives body_fill rather than
                     * height_fill because the rule is about the case, and
                     * body_fill is the one that measures the case rather than
                     * the case plus a raised handle.
                     */
                    bodyFill: $this->caseFill($itemEdits),
                    heightFill: isset($itemEdits['height_fill']) ? (float) $itemEdits['height_fill'] : null,
                );
            }

            /*
             * Sharpened whichever way the size moved, not only when it grew.
             *
             * The gate used to be wasEnlarged, which is false for every
             * photograph in a catalogue run — they all come down, from a 5568
             * px camera file to a 2000 px canvas. So the one step that restores
             * the texture a reduction costs never ran. Measured on a real edit:
             * a garment photographed 3730 px across arrives at 1264 px, and
             * sharpening it lifts the detail metric from 69.2 KB to 82.9 KB
             * with no halo on the print and the fabric's colour moving by a
             * single level of green.
             */
            if ($imageService->wasResized($input, $edited)) {
                $edited = $imageService->sharpenAfterEnlargement($edited);
            }

            unset($input);

            /*
             * Any generative option can hand back a fraction of the resolution
             * it was sent, and none of them say so.
             *
             * Measured on one photograph, same request but for the one field:
             * a cutout alone came back 3333x5000, and the same cutout with
             * ironing came back 832x1248 — a quarter of the size on each edge,
             * a sixteenth of the pixels. The framing then enlarges that to the
             * canvas, which is what "the quality broke" looks like from the
             * outside.
             *
             * Ghost mannequin's 1K ceiling is the documented case; ironing's is
             * not documented at all. So rather than name the features, this
             * measures what arrived against what was sent and says so — which
             * also catches the next option that does it.
             *
             * One hypothesis tested and ruled out, so nobody spends the credit
             * again: it is not the interaction with removeBackground, which
             * Photoroom does warn about elsewhere and whose own ironing example
             * passes removeBackground=false. Ironing alone came back at exactly
             * the same 832x1248 as ironing with the cutout.
             *
             * That size is the tell. 832x1248 is 1.04 megapixels at the input's
             * own aspect ratio, and ghost mannequin's documented 1K is 1024x1024
             * — 1.05 megapixels. The apparel models share a one-megapixel
             * budget, and only one of them says so. Whether a higher plan lifts
             * it, as it does for ghost mannequin's 2K and 4K, is a question for
             * Photoroom rather than something measurable from here.
             */
            $sentEdge = max((int) (@getimagesizefromstring($input)[0] ?? 0), (int) (@getimagesizefromstring($input)[1] ?? 0));
            $gotEdge  = max((int) (@getimagesizefromstring($edited)[0] ?? 0), (int) (@getimagesizefromstring($edited)[1] ?? 0));

            if ($sentEdge > 0 && $gotEdge > 0 && $gotEdge < $sentEdge * 0.6) {
                Log::warning('Photoroom returned far fewer pixels than it was sent', [
                    'item'    => $this->itemId,
                    'sent'    => $this->describeSize($input),
                    'got'     => $this->describeSize($edited),
                    'shrunk'  => round($sentEdge / max(1, $gotEdge), 2) . 'x on the longest edge',
                    'ironing' => !empty($itemEdits['ironing']),
                    'apparel' => $appliedMode,
                ]);
            }


            /*
             * Which tier Photoroom actually gave us. Its app calls 1024, 2048
             * and 4096 standard, advanced and premium; the API documents only
             * "HD" and offers no quality parameter, so the only way to know is
             * to measure what arrives.
             */
            if (in_array($appliedMode, ['ghost_mannequin', 'ghost_print_kept'], true)) {
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

                'apparel_mode_applied' => $appliedMode,

                /*
                 * Which key made this picture, recorded with the picture.
                 *
                 * A sandbox key hands back a watermarked image that has not
                 * really been edited: the background is still there, the
                 * mannequin is still standing in it, and "Photoroom" is written
                 * across the frame. Nothing else here can tell — the status
                 * reads ready and the badge says the mannequin was segmented
                 * out — so the push is stopped by this instead.
                 *
                 * Stored rather than read from the key when pushing, because
                 * those happen on different days: an image made on the sandbox
                 * key does not become safe to publish because somebody has since
                 * switched the key over.
                 */
                'sandbox'              => $photoroom->isSandbox(),

                /*
                 * Not a failure — the image is usable and the mannequin is gone.
                 * It is a caveat the operator needs before publishing: the
                 * redraw moved, reshaped or reworked the garment rather than
                 * lifting it off the stand, so the drape on screen is not the
                 * drape that was photographed.
                 */
                'error_message'        => $redrawNote,

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
    /**
     * What fraction of the canvas the case body should fill.
     *
     * The typed height if there is one, the preset's own figure otherwise.
     *
     * Note what this cannot promise on a handle-up photograph: a raised handle
     * measures about 0.87x its case, so an 80 cm case at 80% would need 150% of
     * the canvas. frameToStandard shrinks the whole product to fit rather than
     * slicing the handle off, so such a shot comes out smaller than asked for —
     * correctly, and visibly. Shoot the larger cases handle-down, as the
     * reference set is.
     */
    /**
     * How far ironing may shift the subject's own colour before the result
     * is thrown away and retried without it.
     *
     * Not measured — there is no failing photograph behind this number,
     * because ironing has no prompt to have gone wrong in a way that
     * produced one yet. Set the same as GhostCompositeService's own colour
     * ceiling, on the same reasoning: a judgement call at a level that would
     * clearly read as "a different colour" to a shopper, not a measurement
     * of where ironing itself actually tends to drift. The number to revisit
     * once a real ironed photo is caught by it.
     */
    private const MAX_IRONING_COLOUR_SHIFT = 0.12;

    /**
     * Which Photoroom route this photo takes.
     *
     * Three inputs decide it now, all of them things the operator set on the
     * screen: whether they typed a product into Keep, whether they ticked
     * Remove the stand, and the photo's own shape. Nothing is inferred about
     * the photograph itself any more.
     *
     * It used to take five more, all of them read out of the picture by
     * Gemini before the edit ran — which side was facing, whether a stand was
     * in frame, what the product was, what was holding it up — and it routed
     * on those. They were wrong often enough to make one ticked checkbox
     * produce four different outcomes across two photos of one dress: a gown
     * on a plainly visible dress form classified as having no stand in it, so
     * no removal was attempted at all; the same dress labelled back on one
     * run and front on the next. Removing the guesswork removes the
     * inconsistency with it.
     *
     * A typed product still wins, and still cuts the stand out of the real
     * photograph rather than redrawing round it — one request, real pixels,
     * and the better route whenever the operator knows the word. Ticking
     * Remove the stand without typing one goes to Ghost Mannequin, every
     * time, on every view.
     */
    private function chooseApparelRoute(
        array $edits,
        int $photoWidth = 0,
        int $photoHeight = 0,
    ): array {
        $itemEdits   = $edits;
        $named       = filled($edits['segmentation_prompt'] ?? null);
        $wantsRedraw = !empty($edits['ghost_mannequin']);

        if ($wantsRedraw && !$named) {
            /*
             * Photoroom's own Ghost Mannequin. Asked for by name on the
             * screen, and reached without anything second-guessing that.
             *
             * The size is named rather than left open, because Photoroom's app
             * exposes quality tiers that turn out to be resolutions — 1024,
             * 2048, 4096 — and 1024 is the tier that destroys a print, at 7% of
             * the original's detail.
             *
             * Which shape was a hardcoded square until it was measured against
             * what was actually failing: several floor-length gowns, each
             * reshaped 49% to 69% on request after request, not the ordinary
             * spread of a generative redraw but a bias in one direction. A
             * gown's own proportions are nowhere near square, and asking the
             * model to fit one onto a square canvas anyway means the model has
             * to choose what to distort to make it fit. Matched to the
             * photograph's own shape instead — cheap, and already close to the
             * garment's own proportions, since a full-length photograph is shot
             * to fit the garment, not the other way round.
             */
            /*
             * No prompt unless the operator typed one.
             *
             * This used to force a 1,100-character instruction into
             * ghostMannequin.prompt — "remove only the stand, change nothing
             * else, do not rotate, do not recolour, do not move any trim, do
             * not redraw the garment" — and the mannequin kept surviving it.
             * Photoroom's own reference says what that field is: "an optional
             * text prompt to guide the generation style", and the single
             * example they give for it is the two words "ghost mannequin".
             * The removal is done by mode=ai.auto on its own.
             *
             * So the wall of prohibitions was never being read as
             * instructions. It was a style hint, and a style hint that ends
             * "do not redraw the garment" is a contradiction handed to a
             * feature whose entire job is to redraw it. Sending nothing lets
             * the model Photoroom tuned for this do it unimpeded, which is
             * the only version of this that was ever going to be consistent.
             *
             * A prompt the operator typed is still sent, because that is a
             * style choice they are making deliberately.
             */
            $itemEdits['apparel_size']   ??= PhotoroomService::closestApparelSize($photoWidth, $photoHeight);
            $itemEdits['apparel_prompt']   = (string) ($edits['apparel_prompt'] ?? '');
            $itemEdits['remove_background'] = true;

            return ['ghost_mannequin', $itemEdits];
        }

        /*
         * Everything else is a cutout. A typed product name makes it a
         * text-guided one, which cuts the stand out of the real photograph;
         * without one it is Photoroom's own matting.
         */
        $itemEdits['ghost_mannequin']   = false;
        $itemEdits['flat_lay']          = false;
        $itemEdits['virtual_model']     = false;
        $itemEdits['remove_background'] = true;

        return [$named ? 'segmented' : 'none', $itemEdits];
    }

    private function caseFill(array $edits): ?float
    {
        $cm = $edits['case_height_cm'] ?? null;

        if (filled($cm) && (float) $cm > 0) {
            return min(0.95, (float) $cm / 100);
        }

        return isset($edits['body_fill']) ? (float) $edits['body_fill'] : null;
    }

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
