<?php

namespace App\Jobs;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\GeminiService;
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
        GeminiService          $gemini,
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
                        (bool) ($classification && !empty($classification['mannequin_visible'])),
                        $classification['product'] ?? null,
                        $classification['support'] ?? null,
                        $classification['support_type'] ?? null,
                        (int) ($rawInfo[0] ?? 0),
                        (int) ($rawInfo[1] ?? 0),
                        $classification['view_type'] ?? null,
                    );

                    /*
                     * The one route that costs a request of its own before the
                     * edit even starts, so it is made here rather than in the
                     * decision. It is also the pass that reinvents prints, which
                     * is why it is the last resort: reached only when nothing
                     * names the product and no redraw was asked for.
                     */
                    if ($appliedMode === 'needs_erase') {
                        $appliedMode = 'none';

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
                $guess     = (string) $itemEdits['segmentation_prompt'];
                $wasGuess  = !empty($itemEdits['segmentation_prompt_is_a_guess']);

                /*
                 * Asked again, without the word.
                 *
                 * Two accurate descriptions of the same photograph — "the top"
                 * and "the cropped top" — both came back as the entire studio,
                 * which says the word was never the problem: a text-guided
                 * segmentation found nothing on this picture and, having been
                 * told to decide the subject from the prompt, had no second
                 * opinion to fall back on. Photoroom's own matting is that
                 * second opinion, and it is what every other photo in the
                 * catalogue already runs on.
                 *
                 * The retry is unguarded by design. A guessed word can be wrong
                 * in ways nothing here can anticipate, so the fallback is the
                 * route with no guess in it at all rather than another guess.
                 *
                 * It does not touch the photograph — the pixels are still the
                 * camera's — so it cannot reintroduce the redraw this was all
                 * meant to avoid. What it can leave behind is the mannequin,
                 * which is visible in the result and is the operator's to judge:
                 * a cutout with a stand still in it can be looked at and fixed
                 * with a typed word. A studio photograph published as a product
                 * cannot be seen at all until a customer sees it.
                 *
                 * One credit, spent only where the first was wasted anyway.
                 */
                Log::warning('EditPhotoItemJob: the named cutout kept the whole frame', [
                    'item'    => $this->itemId,
                    'named'   => $guess,
                    'guessed' => $wasGuess,
                ]);

                /*
                 * Retried only where the app chose the word.
                 *
                 * A guess can be wrong in ways nothing here can anticipate, so
                 * dropping it and falling back to Photoroom's own matting is
                 * worth a credit. A word the operator typed after looking at
                 * the photograph is different: they have already made the
                 * judgement this retry would be second-guessing, and spending
                 * their credit to overrule them is not ours to do. Failing and
                 * saying why leaves the next move with the person who can make
                 * it.
                 */
                if (!$wasGuess) {
                    $item->update([
                        'status'        => 'failed',
                        'error_message' => "Nothing was cut out — \"{$guess}\" found no product in this photo, "
                            . 'so the whole studio came back. Try clearing the "Keep" box to use Photoroom\'s own '
                            . 'background removal, or a different description.',
                    ]);

                    $this->syncSessionCounts($session->id);

                    return;
                }

                $retry = $itemEdits;
                unset(
                    $retry['segmentation_prompt'],
                    $retry['segmentation_prompt_is_a_guess'],
                    $retry['segmentation_negative_prompt'],
                );

                try {
                    $plain = $photoroom->edit($input, $retry, $item->filename);

                    if (!$imageService->looksUncut($plain)) {
                        $edited      = $plain;
                        $itemEdits   = $retry;
                        $appliedMode = 'cutout_unnamed';
                    }
                } catch (\Throwable $e) {
                    Log::warning("EditPhotoItemJob item {$this->itemId} unnamed retry failed: " . $e->getMessage());
                }

                /*
                 * Both routes kept the whole frame. There is nothing left to
                 * try that would not be a guess, and the credits are spent
                 * either way; what remains to decide is whether a studio
                 * photograph is published, which is not a decision to take
                 * silently.
                 */
                if ($appliedMode !== 'cutout_unnamed') {
                    $item->update([
                        'status'        => 'failed',
                        'error_message' => "Nothing was cut out, with or without naming the product (tried \"{$guess}\"). "
                            . 'This photo may need the background removed by hand.',
                    ]);

                    $this->syncSessionCounts($session->id);

                    return;
                }
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
                 * A redraw is not published unless it can be shown not to have
                 * changed the garment.
                 *
                 * The composite is the check and the repair at once. Where the
                 * redraw put the garment back exactly where it stood, the only
                 * thing it knows that the photograph does not is what belongs in
                 * the hole the mannequin left; borrowing that and keeping every
                 * other pixel gives the stand removed at full resolution with
                 * the drape, the direction and the fabric untouched.
                 *
                 * Where it did not, the redraw is refused outright rather than
                 * published with a caveat. This was the other way round and was
                 * wrong: the measurement said "the redraw reworked the garment"
                 * and the image went out anyway with the reason underneath it.
                 * Observed on a sequinned poncho, the redraw returned a cropped
                 * top — the long panel simply gone. A note under a picture of a
                 * garment that does not exist is not a safeguard.
                 *
                 * A refusal it cannot explain counts as a refusal. Unverifiable
                 * and wrong look identical from here, and only one of them is
                 * safe to publish.
                 *
                 * The fallback is the plain cutout: the photograph, with
                 * whatever is holding the garment up still in it. That is a
                 * picture of the real product, which is the whole of what has
                 * been asked for. It costs one more credit, and the operator can
                 * see the stand and decide.
                 */
                $verified   = false;
                $keptRedraw = false;

                try {
                    $whole = $composite->composite($input, $edited);

                    $verified = $whole['accepted'];

                    if ($verified) {
                        $edited      = $whole['image'];
                        $appliedMode = 'ghost_photo_kept';
                    } elseif (
                        $this->wantsRecutKept($edits, $whole['verdict'])
                        && $this->confirmsAsSameGarment($gemini, $input, $edited)
                    ) {
                        /*
                         * Asked for, and checked.
                         *
                         * The refusal above is the right default and stays the
                         * default: a redraw that reworked the garment is a
                         * picture of a product that does not exist. But on a
                         * dress form inside a floor-length skirt the redraw is
                         * refused every time — measured at 41%, 35% and 32% on
                         * three runs of the same two photographs — and the
                         * operator is then handed back a mannequin they asked
                         * to have removed, with no way through. Where they have
                         * looked at that and decided the recut is acceptable
                         * for their catalogue, that is their call to make.
                         *
                         * 'reshaped' and 'redrawn' both land here, on the same
                         * question: is this actually the same garment. There
                         * used to be a numeric ceiling on 'reshaped' instead —
                         * 55%, chosen after a batch published 49-69% blindly —
                         * and it caused exactly the wrong failure on a sequin
                         * gown's own two views: 53.8% (front) kept, 58.1%
                         * (back) refused, four points apart on the same
                         * garment, because a ceiling cannot tell "still the
                         * same dress" from "a different one" any better than
                         * the floor it replaced could. Gemini can, and now
                         * does, for both verdicts, which is why the ceiling is
                         * gone rather than tuned again.
                         *
                         * Only where the garment stayed put. A 'moved' verdict
                         * means the redraw shifted, tilted or resized it, and
                         * keeping the position and direction of the photograph
                         * was the one thing asked for in exchange — so that
                         * refusal is not negotiable and still falls back,
                         * whatever Gemini would say about the garment itself.
                         *
                         * The whole redraw is kept, not the composite: a
                         * composite of two garments that do not line up is the
                         * torn seam this was all written to avoid.
                         */
                        /*
                         * $edited already holds the redraw — it is what came
                         * back from Photoroom and what the composite has just
                         * measured — so keeping it is a matter of not replacing
                         * it. The flag exists because the fallback below is
                         * driven by $verified, which is honestly false: the
                         * composite did refuse. What changed is whether that
                         * refusal ends the matter.
                         */
                        $keptRedraw  = true;
                        $appliedMode = 'ghost_redraw_kept';
                        $redrawNote  = 'The stand was removed by redrawing the garment, which you allowed for '
                            . 'this run. ' . $whole['reason'] . ' The redraw was checked against the original '
                            . 'photograph and confirmed to be the same garment, not a different one in the '
                            . 'right silhouette. The photograph was not used — check the print, the colour '
                            . 'and the drape before pushing.';
                    } elseif ($this->wantsRecutKept($edits, $whole['verdict'])) {
                        /*
                         * Checked and not confirmed — worth saying
                         * differently from a plain refusal, since the
                         * operator did tick "keep the redraw" and this is the
                         * one place a second, garment-level opinion was
                         * actually asked for and came back unable to say it
                         * was the same garment (or could not be reached at
                         * all, which is treated the same way on purpose — a
                         * refusal it cannot explain is the safe default).
                         */
                        $redrawNote = 'The stand could not be removed without altering the garment, so the photo '
                            . 'was kept as shot. ' . $whole['reason'] . ' The redraw was checked against the '
                            . 'original photograph and could not be confirmed as the same garment, so "keep '
                            . 'the redraw" did not apply here.';
                    } else {
                        $redrawNote = 'The stand could not be removed without altering the garment, '
                            . 'so the photo was kept as shot. ' . $whole['reason'];
                    }

                    Log::info('Ghost mannequin composite', [
                        'item'     => $this->itemId,
                        'accepted' => $whole['accepted'],
                        'verdict'  => $whole['verdict'],
                        'reason'   => $whole['reason'],
                    ]);
                } catch (\Throwable $e) {
                    $redrawNote = 'The stand could not be removed without altering the garment, '
                        . 'so the photo was kept as shot.';

                    Log::warning(
                        "EditPhotoItemJob item {$this->itemId} composite could not run: " . $e->getMessage()
                    );
                }

                /*
                 * Not reached when the redraw was kept on purpose. The fallback
                 * would overwrite it with a plain cutout, spend a second credit
                 * doing so, and record the SKU as one whose redraw was refused —
                 * undoing the choice and stopping the next photo of the same
                 * product from redrawing at all.
                 */
                if (!$verified && !$keptRedraw) {
                    $this->rememberTheRedrawWasRefused();

                    /*
                     * Back to a plain cutout. The redraw's own bytes are thrown
                     * away: it is a picture of a garment that was not
                     * photographed, and there is nothing to salvage from one.
                     *
                     * The print transplant used to sit here, putting the real
                     * print over the redraw's shape. It is not reached any more
                     * and that is deliberate — it rescues the fabric, not the
                     * shape, and it was the shape that was wrong. The service
                     * and its command remain for work where the geometry is the
                     * thing being bought.
                     */
                    $plain = $itemEdits;
                    $plain['ghost_mannequin'] = false;
                    $plain['flat_lay']        = false;
                    $plain['virtual_model']   = false;
                    $plain['remove_background'] = true;

                    try {
                        $edited      = $photoroom->edit($input, $plain, $item->filename);
                        $itemEdits   = $plain;
                        $appliedMode = 'cutout_unnamed';
                    } catch (\Throwable $e) {
                        Log::warning(
                            "EditPhotoItemJob item {$this->itemId} fallback cutout failed: " . $e->getMessage()
                        );
                    }
                }
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
            if (!empty($itemEdits['framing_preset'])
                && !$photoroom->generatesOwnCanvas($itemEdits)
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
                'view_type'            => $classification['view_type'] ?? null,
                'mannequin_visible'    => $classification['mannequin_visible'] ?? null,
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
     * Which apparel route a photo takes, and the edits that go with it.
     *
     * Three questions decide it, so all three are settled before any of them is
     * acted on: is a stand actually in shot, is the product named, and was a
     * redraw asked for. Separated from the job's own work because it is a
     * decision with no request in it — the thing most worth testing here, and
     * the thing that needed an API and a queue to reach.
     *
     * @return array{0:string,1:array} the mode, and the edits to send.
     *         'needs_erase' is the caller's to act on: it costs a request.
     */
    /**
     * Has this photo's SKU already had a redraw measured and thrown away?
     *
     * Read per item rather than passed in, because the items of one SKU are
     * edited by separate jobs on separate workers and only the database is
     * shared between them. The first to finish writes it; the rest read it.
     *
     * A race is possible and costs one wasted credit, which is the same as not
     * having this at all. Locking a row to save a penny would be the more
     * expensive mistake.
     */
    /**
     * May a redraw that changed the garment be considered for "keep the
     * redraw" at all?
     *
     * Off unless the operator turned it on for the run, and never for
     * 'moved' — a recut garment is a different product and that is a
     * judgement somebody can make about their own catalogue; a moved one
     * breaks the promise the redraw prompt exists to keep, which is that the
     * photograph's position and direction survive, and no confirmation about
     * the garment itself can buy that back.
     *
     * A true answer here is necessary but not sufficient — the call site
     * also requires confirmsAsSameGarment() before actually keeping the
     * redraw. This method only asks "is this a verdict the checkbox covers
     * at all"; whether this particular redraw is really the same garment is
     * a question only Gemini answers.
     *
     * 'reshaped' and 'redrawn' are treated alike on purpose, which they were
     * not always. 'reshaped' used to also have to clear a 55% ceiling on
     * aspect_shift before Gemini was ever asked — a number chosen after a
     * batch published 49-69% blindly, back when the checkbox had no other
     * check behind it. The ceiling caused the failure it was built to
     * prevent: a sequin gown's front view measured 58.1% and was refused
     * outright, its own back view measured 53.8% and was kept, four points
     * apart on the same dress, because a fixed percentage cannot tell "still
     * the same garment" from "a different one" any better than the total
     * absence of a check that came before it. Gemini can tell the difference
     * directly, so the ceiling was removed rather than moved again — the
     * same reasoning 'redrawn' was already built on.
     */
    private function wantsRecutKept(array $edits, string $verdict): bool
    {
        return !empty($edits['accept_recut_redraw']) && in_array($verdict, ['reshaped', 'redrawn'], true);
    }

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
     * Ask Gemini whether a redraw being considered for "keep the redraw" is
     * really the same garment.
     *
     * Called for two different verdicts, for two different blind spots. For
     * 'redrawn', a faithful redraw of a heavily draped or sheer garment can
     * measure the same mask_coverage percentage as a genuine failure — the
     * number cannot tell them apart. For 'reshaped', aspect_shift only
     * checks the garment's outline; a men's jeans back view measured well
     * inside the reshape ceiling and still came back with its back-pocket
     * patch relocated to the waistband, a real design change the outline
     * never saw. Neither number was ever measuring what this asks about
     * directly, so both are checked against the actual garment instead.
     *
     * A refusal it cannot explain still counts as a refusal here, the same
     * rule the composite itself follows: a quota exception, a timeout, or an
     * answer Gemini would not commit to all come back false, not true. The
     * cost of asking again on the next run is one Gemini call; the cost of
     * guessing yes is a picture of a product that does not exist.
     */
    private function confirmsAsSameGarment(GeminiService $gemini, string $original, string $redraw): bool
    {
        try {
            return $gemini->confirmSameGarment($original, $redraw) === true;
        } catch (\Throwable $e) {
            Log::warning(
                "EditPhotoItemJob item {$this->itemId} same-garment check could not run: " . $e->getMessage()
            );

            return false;
        }
    }

    private function skuAlreadyRefusedARedraw(): bool
    {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item || !filled($item->sku_detected)) {
            return false;
        }

        return PhotoEditGroup::where('photo_edit_session_id', $item->photo_edit_session_id)
            ->where('sku', $item->sku_detected)
            ->value('redraw_refused') ? true : false;
    }

    private function rememberTheRedrawWasRefused(): void
    {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item || !filled($item->sku_detected)) {
            return;
        }

        PhotoEditGroup::where('photo_edit_session_id', $item->photo_edit_session_id)
            ->where('sku', $item->sku_detected)
            ->update(['redraw_refused' => true]);
    }

    private function chooseApparelRoute(
        array $edits,
        bool $standVisible,
        ?string $seen = null,
        ?string $support = null,
        ?string $supportType = null,
        int $photoWidth = 0,
        int $photoHeight = 0,
        ?string $viewType = null,
    ): array {
        $itemEdits   = $edits;
        $named       = filled($edits['segmentation_prompt'] ?? null);
        $wantsRedraw = !empty($edits['ghost_mannequin']);

        /*
         * Nobody typed a product, but the category knows one.
         *
         * PRODUCT_NOUNS has said as much since it was written — "a category has
         * already been told what the product is, so there is no reason to make
         * anyone type it" — and nothing ever read it. So a categorised run with
         * the redraw ticked went to Ghost Mannequin, which does not erase a
         * mannequin from a photograph: it generates a new garment from what it
         * sees. On a draped poncho it read a wide sheet of sequinned fabric as
         * sleeves and hung them straight down, and the back view came back a
         * different garment from its own front view.
         *
         * Naming the product instead cuts the mannequin out of the real
         * photograph, in one request rather than two, and the pixels that come
         * back are the ones the camera recorded. That is the point rather than a
         * saving: nothing downstream can tell a confident redraw from a
         * photograph, so the only safe moment to refuse one is before it is
         * asked for.
         *
         * Only where a mannequin is actually in shot. A cutout with nothing to
         * erase is working already, and swapping its matting for a text prompt
         * would be changing what is not broken.
         */
        /*
         * Whether naming the product can work at all depends on what is holding
         * it up, and the two cases are not alike.
         *
         * A hanger, a rail, a bag stand — these hold the product from outside.
         * Naming the product cuts them out of the real photograph and every
         * pixel of the product survives, which is strictly better than any
         * generative route and is what the naming was built for.
         *
         * A dress form is inside the garment. It shows through the neck and
         * below the hem, and it is what the garment takes its shape from. There
         * is no cutting that away: what is behind it is the inside of the
         * garment, which the photograph does not contain. Asked to try, the
         * cutout keeps the form — which is what was reported, a mannequin
         * returned still wearing the poncho after the background came away
         * cleanly. Only a redraw removes one, and a redraw is a redraw.
         *
         * So the guess is offered where it can succeed and withheld where it
         * cannot. An unknown support falls through to the redraw, because the
         * operator asked for the stand to go and that is the route that can
         * always do it.
         */
        $canBeCutAway = $supportType === 'held';

        if (!$named && $standVisible && $canBeCutAway) {
            /*
             * What the classifier saw, before what the folder is called.
             *
             * The classifier looked at this photograph; the category describes
             * a folder. A scarf worn as a cape, filed under tops because that is
             * how it is merchandised, is "the top" to the category and "the
             * scarf" to anything with eyes — and the cutout is being told what
             * to find in this picture, not which folder it came from.
             *
             * The category stays as the fallback. It is a guess too, but a short
             * predictable one, and it is there when the classifier fails or is
             * never called.
             */
            $noun = filled($seen)
                ? $seen
                : PhotoroomService::productNoun($edits['framing_preset'] ?? null);

            if (filled($noun)) {
                $itemEdits['segmentation_prompt'] = $noun;

                // A guess about a folder, not a statement about this photograph.
                // applySegmentation() reads this and keeps Photoroom's own
                // matting in play rather than betting the cutout on one word.
                $itemEdits['segmentation_prompt_is_a_guess'] = true;

                /*
                 * And what to drop, where the classifier saw it.
                 *
                 * Naming the product alone was not enough on a garment draped
                 * over a dress form: the background came off and the form
                 * stayed, because nothing had said the form was not part of the
                 * product. Saying so is what the negative prompt is for.
                 *
                 * Only what was actually seen. "The mannequin" is wrong for a
                 * garment on a hanger, and a negative prompt naming something
                 * that is not in the picture is worse than no negative prompt at
                 * all — it gives the model a second thing to fail to find.
                 *
                 * A typed one is never overwritten, for the same reason a typed
                 * product is not: somebody looked.
                 */
                if (filled($support) && !filled($edits['segmentation_negative_prompt'] ?? null)) {
                    $itemEdits['segmentation_negative_prompt'] = $support;
                }

                $named = true;
            }
        }

        /*
         * Already tried on this SKU, already thrown away.
         *
         * A refused redraw costs a credit and produces nothing — the image that
         * gets published is the cutout bought afterwards — so the SKU pays twice
         * for every photo. Once is the price of finding out. Ten times on one
         * folder is waste, and a folder is ten photographs of the same garment
         * on the same stand: whatever the redraw did to the first is what it
         * will do to the rest.
         */
        if ($wantsRedraw && $standVisible && !$named && $this->skuAlreadyRefusedARedraw()) {
            $itemEdits['ghost_mannequin']   = false;
            $itemEdits['flat_lay']          = false;
            $itemEdits['virtual_model']     = false;
            $itemEdits['remove_background'] = true;

            return ['cutout_unnamed', $itemEdits];
        }

        if ($wantsRedraw && $standVisible && !$named && $viewType !== 'back' && $viewType !== 'side') {
            /*
             * Photoroom's own Ghost Mannequin, for a category nobody has given a
             * word to. This used to be switched off here and replaced with a
             * generic editWithAI pass, on the grounds that generative
             * reconstruction could not be trusted with a garment's colour or
             * orientation — a fair call, made against the wrong feature.
             *
             * Side by side on one shirt: editWithAI reinvented an Aigner
             * horseshoe monogram as rings, at 4% of the original's print detail.
             * Ghost Mannequin reproduced the horseshoes. One is apparel-aware;
             * the other is a general image editor being asked to understand a
             * garment.
             *
             * Restricted to a front view (or a view nothing was told about) for
             * a reason documented against this feature since before this
             * branch existed: "Ghost Mannequin only reconstructs front views,
             * so it can't help a back or side shot where the stand is left
             * visible" — see MANNEQUIN_REMOVAL_PROMPT's own docblock in
             * PhotoroomService. Nothing here had ever acted on that. A sequin
             * gown's back view — straps crossing behind a bare back, the
             * mannequin's torso visible between them — went to Ghost Mannequin
             * anyway and came back a front view of the same dress: a V-neck
             * and thin straps over the chest, a garment reconstructed from
             * scratch rather than a photograph with a stand lifted out of it.
             * A back or side view now falls through to the erase pass instead
             * (below, 'needs_erase'), which inpaints only the stand and leaves
             * every other pixel — and the view actually photographed — alone.
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
            $itemEdits['apparel_size']   ??= PhotoroomService::closestApparelSize($photoWidth, $photoHeight);
            $itemEdits['apparel_prompt']   = filled($edits['apparel_prompt'] ?? null)
                ? $edits['apparel_prompt']
                : PhotoroomService::GHOST_MANNEQUIN_PROMPT;
            $itemEdits['remove_background'] = true;

            return ['ghost_mannequin', $itemEdits];
        }

        /*
         * Everything else is a plain cutout. Nothing to erase, or no redraw was
         * asked for, or the product is named — and naming it is the better route
         * anyway: one request rather than two, cutting the stand out of the real
         * photograph rather than redrawing round it.
         */
        $itemEdits['ghost_mannequin']   = false;
        $itemEdits['flat_lay']          = false;
        $itemEdits['virtual_model']     = false;
        $itemEdits['remove_background'] = true;

        if ($standVisible && $named) {
            return ['segmented', $itemEdits];
        }

        return [$standVisible ? 'needs_erase' : 'none', $itemEdits];
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
