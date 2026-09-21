<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A categorised run keeps the photograph rather than being redrawn.
 *
 * Ghost Mannequin does not erase a mannequin from a photograph. It generates a
 * new garment from what it sees, and on anything draped or irregular it guesses
 * — a sequinned poncho's back view came back with the fabric hanging as sleeves,
 * a different garment from its own front view. The badge said REDRAWN BY AI,
 * which was accurate and too late: nothing downstream can tell a confident
 * redraw from a photograph, so the only place to refuse one is before it is
 * asked for.
 *
 * Every category already knows what its product is. Naming it cuts the mannequin
 * out of the real photograph in a single request, and the pixels that come back
 * are the ones the camera recorded.
 */
class PhotoEditorNoRedrawTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The whole pipeline, when the named cutout comes back uncut.
     *
     * Two accurate descriptions of one photograph — "the top" and "the cropped
     * top" — both returned the entire studio, which says the word was never the
     * problem: a text-guided segmentation found nothing, and having been told to
     * decide the subject from the prompt it had no second opinion to fall back
     * on. So it asks again without the word, on Photoroom's own matting, which
     * is what every other photo in the catalogue already runs on.
     */
    public function test_a_named_cutout_that_finds_nothing_is_retried_without_the_name(): void
    {
        $uncut  = $this->solidRectangle();
        $cutout = $this->garmentCutout();
        $calls  = 0;

        // Uncut first, a real cutout second: the retry is the only thing that
        // can turn this item into a success.
        Http::fake([
            'image-api.photoroom.com/*' => function () use (&$calls, $uncut, $cutout) {
                return Http::response(++$calls === 1 ? $uncut : $cutout, 200);
            },
        ]);

        $item = $this->runItem(['framing_preset' => 'women/top']);

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertSame('cutout_unnamed', $item->apparel_mode_applied,
            'the retry did not happen, or was not recorded for the operator to see');

        Http::assertSentCount(2);
    }

    /**
     * When neither route cuts anything out there is nothing left to try that
     * would not be a guess, and a studio photograph must not be published as a
     * product because nothing downstream reads the pixels.
     */
    public function test_an_item_no_route_can_cut_out_is_failed_rather_than_published(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->solidRectangle(), 200),
        ]);

        $item = $this->runItem(['framing_preset' => 'women/top']);

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('with or without naming the product', (string) $item->error_message);

        Http::assertSentCount(2);
    }

    /** One item through the real job, with OneDrive and Gemini stood in for. */
    private function runItem(
        array $edits,
        array $classification = [],
        ?bool $confirmSameGarment = null,
        bool $confirmNoStandVisible = true,
    ): PhotoEditItem {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => array_merge(['remove_background' => true, 'ghost_mannequin' => true], $edits),
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        $oneDrive = \Mockery::mock(\App\Services\OneDriveService::class);
        $oneDrive->shouldReceive('setUser')->andReturnSelf();
        $oneDrive->shouldReceive('downloadFileById')->andReturn($this->garmentCutout());

        $gemini = \Mockery::mock(\App\Services\GeminiService::class);
        $gemini->shouldReceive('classifyGarmentView')->andReturn(array_merge([
            'view_type'         => 'front',
            'mannequin_visible' => true,
            'product'           => 'the cropped top',
            'support'           => 'the hanger',

            // Held, not worn — the case where naming the product is offered at
            // all, and therefore the case where a bad guess has to be caught.
            'support_type'      => 'held',
        ], $classification));

        // Left unset for every test that is not exercising the 'redrawn'
        // branch on purpose. A strict Mockery mock throws loudly if
        // confirmSameGarment() is called without an expectation, which is
        // exactly the regression this guards: the checkbox short-circuits to
        // never asking Gemini at all when it is off, or when the verdict
        // isn't 'redrawn' in the first place.
        if ($confirmSameGarment !== null) {
            $gemini->shouldReceive('confirmSameGarment')->once()->andReturn($confirmSameGarment);
        }

        // Confirmed clean by default — these tests are about routing and the
        // redraw pipeline, not this check, so a real photo's stand genuinely
        // being gone is the ordinary case to assume unless a test says
        // otherwise.
        $gemini->shouldReceive('confirmNoStandVisible')->andReturn($confirmNoStandVisible);

        (new EditPhotoItemJob($item->id))->handle(
            $oneDrive,
            app(\App\Services\ImageProcessingService::class),
            app(PhotoroomService::class),
            $gemini,
            app(\App\Services\GhostPrintTransplantService::class),
            app(\App\Services\GhostCompositeService::class),
        );

        return $item->fresh();
    }

    /**
     * The redraw Photoroom sends back for a garment it has reworked: the same
     * subject, narrower and shorter, so the proportions no longer match the
     * photograph and the composite has nothing to line up against.
     */
    private function recutGarment(): string
    {
        $im = imagecreatetruecolor(900, 1200);

        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $c = imagecolorallocate($im, 150, 150, 152);

        /*
         * Same top and bottom, and inside the original's silhouette, so it has
         * not moved — but a good deal narrower, so its proportions have
         * changed. That is the verdict this is for: 'reshaped', not 'moved'.
         */
        imagefilledrectangle($im, 330, 150, 570, 1050, $c);
        imagefilledrectangle($im, 220, 150, 330, 520, $c);
        imagefilledrectangle($im, 570, 150, 680, 520, $c);

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** An uncut photograph: a solid opaque rectangle, studio and all. */
    private function solidRectangle(): string
    {
        $im = imagecreatetruecolor(900, 1200);

        for ($y = 0; $y < 1200; $y += 10) {
            for ($x = 0; $x < 900; $x += 10) {
                imagefilledrectangle($im, $x, $y, $x + 9, $y + 9,
                    imagecolorallocate($im, 120 + ($x % 60), 110 + ($y % 60), 130));
            }
        }

        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    /** A garment on transparency, with real holes under the sleeves. */
    private function garmentCutout(): string
    {
        $im = imagecreatetruecolor(900, 1200);

        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $c = imagecolorallocate($im, 150, 150, 152);

        imagefilledrectangle($im, 280, 150, 620, 1050, $c);
        imagefilledrectangle($im, 60, 150, 280, 520, $c);
        imagefilledrectangle($im, 620, 150, 840, 520, $c);

        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    /**
     * The redraw Photoroom sends back for a garment whose surface it has
     * reworked: the exact same box as garmentCutout() — same position, same
     * proportions, so containment and aspect_shift both pass — filled with a
     * shade far enough from the original that most of it counts as changed
     * per pixel. Near-neutral on purpose, the same trick as a real near-white
     * or grey garment: too little chroma for colour_shift to have an opinion,
     * so this is measured as a 'redrawn' verdict on mask_coverage alone, not
     * as 'recoloured'.
     */
    private function redrawnDifferentSurface(): string
    {
        $im = imagecreatetruecolor(900, 1200);

        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $c = imagecolorallocate($im, 40, 40, 42);

        imagefilledrectangle($im, 280, 150, 620, 1050, $c);
        imagefilledrectangle($im, 60, 150, 280, 520, $c);
        imagefilledrectangle($im, 620, 150, 840, 520, $c);

        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    /** The decision under test, run without a queue or an API behind it. */
    private function route(
        array $edits,
        bool $mannequinVisible,
        ?string $seen = null,
        ?string $support = null,
        ?string $supportType = 'held',
        int $photoWidth = 0,
        int $photoHeight = 0,
        ?string $viewType = null,
    ): array {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(
            new EditPhotoItemJob(1), $edits, $mannequinVisible, $seen, $support, $supportType,
            $photoWidth, $photoHeight, $viewType,
        );
    }

    /**
     * The case that was reported: a categorised run, the redraw ticked, a
     * mannequin in shot. It must still not redraw.
     */
    public function test_a_category_names_its_product_instead_of_redrawing(): void
    {
        [$mode, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
        );

        $this->assertSame('segmented', $mode, 'a categorised run was still sent to be redrawn');
        $this->assertSame('the top', $itemEdits['segmentation_prompt'] ?? null);
        $this->assertFalse((bool) ($itemEdits['ghost_mannequin'] ?? false));
    }

    /**
     * Every category with a product noun names it, not just tops — so no run
     * that left the redraw alone can reach a generative pass by accident.
     */
    public function test_every_named_category_is_cut_out_rather_than_erased(): void
    {
        foreach (PhotoroomService::PRODUCT_NOUNS as $key => $noun) {
            [$mode, $itemEdits] = $this->route(
                ['framing_preset' => $key, 'ghost_mannequin' => true],
                true,
            );

            $this->assertSame('segmented', $mode, "{$key} is still sent to be redrawn");
            $this->assertSame($noun, $itemEdits['segmentation_prompt'] ?? null);
        }
    }

    /**
     * What the classifier saw beats what the folder is called.
     *
     * This is the case that was reported. A scarf worn as a cape, filed under
     * tops because that is how it is merchandised, was described to the cutout
     * as "the top" — and a text-guided segmentation handed a word that matches
     * nothing selects nothing, so the mannequin and the studio floor came back
     * with it. The classifier is already looking at the photograph and already
     * paid for; it names the garment in front of the camera.
     */
    public function test_what_the_classifier_saw_beats_the_folder_it_came_from(): void
    {
        [$mode, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            'the scarf',
        );

        $this->assertSame('segmented', $mode);
        $this->assertSame('the scarf', $itemEdits['segmentation_prompt'] ?? null,
            'the folder\'s word was used on a photograph of something else');
    }

    /** No classification, or none it trusted: the category is still there. */
    public function test_the_category_is_the_fallback_when_nothing_was_seen(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            null,
        );

        $this->assertSame('the top', $itemEdits['segmentation_prompt'] ?? null);
    }

    /**
     * What is holding the garment up is named too, so it can be dropped.
     *
     * Naming the product alone was not enough on a poncho draped over a dress
     * form: the background came away cleanly and the mannequin was left
     * standing in it, wearing the garment, because nothing had said the form was
     * not part of the product.
     */
    public function test_the_thing_holding_the_garment_up_is_named_as_well(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            'the scarf',
            'the mannequin',
        );

        $this->assertSame('the scarf', $itemEdits['segmentation_prompt'] ?? null);
        $this->assertSame('the mannequin', $itemEdits['segmentation_negative_prompt'] ?? null,
            'nothing told the cutout that the dress form was not the product');
    }

    /**
     * And only what was seen. "The mannequin" is wrong for a garment on a
     * hanger, and a negative prompt naming something that is not in the picture
     * gives the model a second thing to fail to find.
     */
    public function test_no_support_is_invented_when_none_was_seen(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            'the scarf',
            null,
        );

        $this->assertArrayNotHasKey('segmentation_negative_prompt', $itemEdits);
    }

    /** What somebody typed themselves wins over both. */
    public function test_a_typed_product_is_not_overwritten(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'segmentation_prompt' => 'the poncho', 'ghost_mannequin' => true],
            true,
            'the scarf',
        );

        $this->assertSame('the poncho', $itemEdits['segmentation_prompt'] ?? null);

        // And a word somebody chose is trusted, so it keeps the confident mode.
        $this->assertArrayNotHasKey('segmentation_prompt_is_a_guess', $itemEdits);
    }

    /**
     * A cutout with nothing to erase is working already. Swapping its matting
     * for a text prompt would be changing what is not broken.
     */
    public function test_a_photo_with_no_mannequin_is_left_to_its_own_matting(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            false,
        );

        $this->assertNull($itemEdits['segmentation_prompt'] ?? null);
    }

    /**
     * A dress form cannot be cut out of a photograph, so naming is not offered.
     *
     * It is inside the garment: it shows through the neck, it is what the
     * garment takes its shape from, and what is behind it is the inside of the
     * garment, which the photograph does not contain. Asked to try, a
     * segmentation-based cutout keeps it — a mannequin returned still wearing
     * the poncho after the background came away cleanly, which is what was
     * reported.
     *
     * Ticking "Remove the stand (Ghost Mannequin)" still reaches the redraw
     * for a worn dress form on a front view, exactly as it always has. This
     * was briefly narrowed to hangers only, on the discovery that the redraw
     * can recut a real, fitted silhouette into a generic shape — a sequin
     * gown's front photo came back 56.6% recut. But the erase pass has its
     * own failure the redraw does not: reported on a different worn dress
     * form, a visible piece of the form left in shot because the gap it
     * showed through was too large to inpaint plausibly. Between a redraw
     * that can misjudge the cut and an erase that can leave the stand half
     * in shot, the checkbox is what the operator ticks to say which risk
     * they are choosing for this run.
     */
    public function test_a_worn_dress_form_goes_to_the_redraw_rather_than_a_cutout(): void
    {
        [$mode] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            'the scarf',
            'the mannequin',
            'worn',
        );

        $this->assertSame('ghost_mannequin', $mode,
            'a garment on a dress form was sent to a cutout that cannot remove one');
    }

    /**
     * A back or side view still refuses the redraw regardless of the
     * checkbox — not a risk the operator can choose to take, because it is
     * not a quality tradeoff. Photoroom's Ghost Mannequin only reconstructs
     * front views at all; sent a back view, it does not produce a worse
     * redraw of the right garment, it produces a confident front view of the
     * wrong one. A sequin gown's back view came back a front view of the
     * same dress. There is no operator preference that makes that useful, on
     * a worn dress form or a held one, so both fall through to the erase
     * pass here instead.
     */
    public function test_a_back_or_side_view_is_erased_not_redrawn_whatever_the_support(): void
    {
        foreach (['worn', 'held', null] as $supportType) {
            foreach (['back', 'side'] as $viewType) {
                [$mode] = $this->route(
                    ['framing_preset' => 'women/gown-unlisted', 'ghost_mannequin' => true],
                    true, null, 'the mannequin', $supportType, 0, 0, $viewType,
                );

                $this->assertSame(
                    'needs_erase',
                    $mode,
                    "{$viewType} view with support '" . ($supportType ?? 'unknown') . "' went to the redraw anyway",
                );
            }
        }
    }

    /** A front view — or a view nothing was told about — still gets the redraw, worn or held. */
    public function test_a_front_or_unclassified_view_still_gets_the_redraw(): void
    {
        foreach (['worn', 'held', null] as $supportType) {
            foreach (['front', null] as $viewType) {
                [$mode] = $this->route(
                    ['framing_preset' => 'women/gown-unlisted', 'ghost_mannequin' => true],
                    true, null, 'the mannequin', $supportType, 0, 0, $viewType,
                );

                $this->assertSame(
                    'ghost_mannequin',
                    $mode,
                    ($viewType ?? 'unclassified') . " view with support '" . ($supportType ?? 'unknown')
                        . "' did not reach the redraw",
                );
            }
        }
    }

    /**
     * The erase pass gets the same after-the-fact check the redraw already
     * has, because it turned out to need one just as much.
     *
     * GhostCompositeService checks every redraw against its photograph;
     * this pass checked nothing, on the theory that inpainting a stand away
     * is a narrower job than redrawing a garment. A real jeans photo said
     * otherwise twice: the mannequin genuinely gone, badge green, and the
     * leather back-pocket patch either relocated to the waistband or, once
     * that was named in the prompt, missing from the pocket while a new one
     * appeared at the waistband anyway. Gemini is asked the same question
     * asked of a redraw — is this still the same garment — and an
     * unconfirmed answer downgrades the mode rather than the item, since the
     * stand really is gone either way.
     */
    public function test_an_unconfirmed_mannequin_removal_is_flagged_not_hidden(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->garmentCutout(), 200),
        ]);

        $item = $this->runItem(
            ['framing_preset' => 'women/gown-unlisted'],
            ['product' => null, 'support_type' => 'worn', 'view_type' => 'back'],
            confirmSameGarment: false,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $this->assertSame('mannequin_removed_unverified', $item->apparel_mode_applied,
            'an unconfirmed mannequin removal was labelled as a plain, unchecked success');

        $this->assertStringContainsString('could not be confirmed', (string) $item->error_message);
    }

    /** The ordinary case: Gemini confirms the trim survived, and the mode says so plainly. */
    public function test_a_confirmed_mannequin_removal_is_not_flagged(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->garmentCutout(), 200),
        ]);

        $item = $this->runItem(
            ['framing_preset' => 'women/gown-unlisted'],
            ['product' => null, 'support_type' => 'worn', 'view_type' => 'back'],
            confirmSameGarment: true,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertSame('mannequin_removed', $item->apparel_mode_applied);
    }

    /**
     * The stand-visibility check runs independently of confirmsAsSameGarment
     * and of whatever mode the item ended up in — a real batch showed why:
     * a back-view gown passed the same-garment check cleanly (it genuinely
     * was the same garment) while a pale sliver of the mannequin's shoulder
     * was still visible above the neckline, which that check was never
     * asking about. Confirmed here on the erase path specifically, since
     * that is the mode the real failure was found on.
     */
    public function test_a_stand_still_visible_after_mannequin_removal_is_flagged(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->garmentCutout(), 200),
        ]);

        $item = $this->runItem(
            ['framing_preset' => 'women/gown-unlisted'],
            ['product' => null, 'support_type' => 'worn', 'view_type' => 'back'],
            confirmNoStandVisible: false,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertTrue((bool) $item->stand_visible_after_edit,
            'a stand left visible in the delivered image was not recorded');
        $this->assertStringContainsString('mannequin or stand may still be visible', (string) $item->error_message);
    }

    /** The ordinary case: a stand confirmed gone leaves the item unflagged. */
    public function test_a_confirmed_clean_result_is_not_flagged_for_a_stand(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->garmentCutout(), 200),
        ]);

        $item = $this->runItem(
            ['framing_preset' => 'women/gown-unlisted'],
            ['product' => null, 'support_type' => 'worn', 'view_type' => 'back'],
            confirmNoStandVisible: true,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertFalse((bool) $item->stand_visible_after_edit);
    }

    /**
     * Nothing routed through the apparel pipeline at all — no category
     * ticked a redraw mode — so there is no classification for this check
     * to run alongside, and it is left unrecorded rather than guessed at.
     * confirmNoStandVisible has no expectation set on this run's mock, so a
     * call here fails the test loudly rather than silently passing.
     */
    public function test_an_item_outside_the_apparel_pipeline_is_never_stand_checked(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->solidRectangle(), 200),
        ]);

        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        $oneDrive = \Mockery::mock(\App\Services\OneDriveService::class);
        $oneDrive->shouldReceive('setUser')->andReturnSelf();
        $oneDrive->shouldReceive('downloadFileById')->andReturn($this->garmentCutout());

        $gemini = \Mockery::mock(\App\Services\GeminiService::class);

        (new EditPhotoItemJob($item->id))->handle(
            $oneDrive,
            app(\App\Services\ImageProcessingService::class),
            app(PhotoroomService::class),
            $gemini,
            app(\App\Services\GhostPrintTransplantService::class),
            app(\App\Services\GhostCompositeService::class),
        );

        $item = $item->fresh();

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertNull($item->stand_visible_after_edit);
    }

    /**
     * And an unrecognised support falls through to the redraw, because that is
     * the route that can always remove one and the operator did ask.
     */
    public function test_an_unknown_support_falls_through_to_the_redraw(): void
    {
        [$mode] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            'the scarf',
            'the mannequin',
            null,
        );

        $this->assertSame('ghost_mannequin', $mode);
    }

    /**
     * The redraw canvas matches the photo's own shape rather than always
     * being square.
     *
     * apparel_size used to default to SQUARE_HD regardless of what was
     * photographed. A batch of floor-length gowns — naturally tall, not
     * square — came back recut by 49-69% across several SKUs, which is what
     * happens when a model is asked to fill a frame shaped differently than
     * the garment it is drawing. Passing the photo's real dimensions through
     * now picks the nearest-shaped preset instead.
     */
    public function test_the_redraw_canvas_matches_a_tall_photos_own_shape(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            null,
            null,
            null,
            900,
            1600,
        );

        $this->assertSame('PORTRAIT_HD_16_9', $itemEdits['apparel_size'] ?? null,
            'a tall gown photo was still sent to the square canvas');
    }

    /** No photo dimensions available — the old, safe default still applies. */
    public function test_the_redraw_canvas_defaults_to_square_without_photo_dimensions(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            null,
            null,
            null,
        );

        $this->assertSame('SQUARE_HD', $itemEdits['apparel_size'] ?? null);
    }

    /**
     * A SKU only pays for one refused redraw, not one per photograph.
     *
     * A refusal costs a credit and produces nothing — the published image is the
     * cutout bought afterwards — so every photo of that SKU was charged twice.
     * Once is the price of finding out; ten times on a folder of ten photographs
     * of the same garment on the same stand is waste.
     */
    public function test_a_sku_that_has_refused_one_redraw_does_not_buy_another(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true, 'ghost_mannequin' => true],
        ]);

        \App\Models\PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'AFP204BTM00057',
            'edits'                 => null,
            'redraw_refused'        => true,
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'b.jpg',
            'sku_detected'          => 'AFP204BTM00057',
            'status'                => 'pending',
        ]);

        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        [$mode, $itemEdits] = $method->invoke(
            new EditPhotoItemJob($item->id),
            ['framing_preset' => 'women/skirts', 'ghost_mannequin' => true],
            true,
            null,
            'the mannequin',
            'worn',
        );

        $this->assertSame('cutout_unnamed', $mode, 'the SKU was charged for a second refused redraw');
        $this->assertFalse((bool) ($itemEdits['ghost_mannequin'] ?? false));
    }

    /**
     * The redraw is still reachable for a category nobody has given a word to,
     * which is what it was always for.
     */
    public function test_an_unnamed_category_can_still_be_redrawn(): void
    {
        [$mode] = $this->route(
            ['framing_preset' => 'women/gown-unlisted', 'ghost_mannequin' => true],
            true,
        );

        $this->assertSame('ghost_mannequin', $mode);
    }

    /**
     * A word the operator typed is checked too, and failed rather than retried.
     *
     * The check used to run only on the app's own guesses, on the reasoning
     * that somebody looking at the photograph could be trusted to name it. A
     * typed "the skirt" then came back as a mannequin standing in a studio,
     * marked ready, and nothing looked. Whether a cutout worked is a fact about
     * the pixels; who chose the word has no bearing on it.
     *
     * It is failed rather than retried because the operator has already made
     * the judgement a retry would be overruling, and it is their credit.
     */
    public function test_a_typed_name_that_cuts_nothing_out_fails_instead_of_publishing(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->solidRectangle(), 200),
        ]);

        $item = $this->runItem([
            'framing_preset'      => 'women/skirts',
            'segmentation_prompt' => 'the skirt',   // typed, so never a guess
        ]);

        $this->assertSame('failed', $item->status,
            'a studio photograph went out as ready because the operator typed the word');

        $this->assertStringContainsString('the skirt', (string) $item->error_message,
            'the message should name the word that found nothing');

        // One request, not two: a typed word is not second-guessed with another
        // credit.
        Http::assertSentCount(1);
    }

    /**
     * A redraw kept on purpose must actually reach the file.
     *
     * The first version of this set the label and the note and left the image
     * alone, so the fallback below ran anyway: it overwrote the redraw with a
     * plain cutout, spent a second credit doing it, and recorded the SKU as one
     * whose redraw had been refused. The item then said the stand had been
     * removed by redrawing while showing a photograph of the stand.
     */
    public function test_an_allowed_recut_redraw_is_the_image_that_is_kept(): void
    {
        $calls = 0;

        Http::fake([
            'image-api.photoroom.com/*' => function () use (&$calls) {
                $calls++;

                // A redraw of a different cut — which is the whole case: the
                // stand is gone and the garment is not the one photographed.
                return Http::response($this->recutGarment(), 200);
            },
        ]);

        $item = $this->runItem(
            [
                'framing_preset'      => 'women/skirts',
                'accept_recut_redraw' => true,
            ],
            /*
             * Nothing named and no support classified: the route that still
             * reaches the redraw. A worn dress form no longer does — it goes
             * to the erase pass instead, whatever the view — and a named
             * product goes to text-guided segmentation, a different failure
             * with a different answer.
             */
            ['product' => null, 'support_type' => null],
            /*
             * A 'reshaped' verdict within the ceiling is no longer kept on the
             * ceiling alone — a men's jeans back view showed why: measured
             * well inside 55% on aspect_shift, and still came back with its
             * back-pocket patch moved to the waistband. Gemini is asked here
             * too now, the same question already asked for 'redrawn'.
             */
            confirmSameGarment: true,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $this->assertSame('ghost_redraw_kept', $item->apparel_mode_applied,
            'the fallback overwrote the redraw the operator asked to keep');

        // One request. The fallback cutout would have been a second.
        $this->assertSame(1, $calls, 'a second credit was spent undoing the choice');
    }

    /**
     * A 'reshaped' verdict is still refused when Gemini cannot confirm it is
     * the same garment — there is no numeric ceiling to fall back on instead.
     *
     * The gap this closes: aspect_shift only measures the garment's outline.
     * A men's jeans back view measured well inside the reshape ceiling that
     * used to gate this alone, and still came back with its leather
     * back-pocket patch relocated to the waistband — the outline recut
     * cleanly, the patch did not travel with it, and a percentage had no way
     * to see that. The same confirmation already required for 'redrawn' is
     * required here too, now the only check either verdict gets.
     */
    public function test_a_reshaped_redraw_is_still_refused_without_gemini_confirmation(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->recutGarment(), 200),
        ]);

        $item = $this->runItem(
            [
                'framing_preset'      => 'women/skirts',
                'accept_recut_redraw' => true,
            ],
            ['product' => null, 'support_type' => null],
            confirmSameGarment: false,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $this->assertNotSame('ghost_redraw_kept', $item->apparel_mode_applied,
            'an unconfirmed reshape was published anyway');

        $this->assertStringContainsString('could not be confirmed', (string) $item->error_message);
    }

    /**
     * A 'redrawn' verdict — the garment's own surface differs by more than a
     * third — is still kept when Gemini looks at both photos and confirms it
     * is the same garment.
     *
     * The case this closes: a ruffled chiffon dress, front and back views,
     * refused at 72.0% and 74.6% surface difference on every run, with the
     * operator's "keep the redraw" checkbox ticked and doing nothing, because
     * mask_coverage alone cannot tell a faithfully redrawn sheer garment that
     * simply re-drapes once nothing is holding its pose from an actually
     * reworked one. Asked directly, Gemini can.
     */
    public function test_a_redrawn_verdict_is_kept_when_gemini_confirms_the_same_garment(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->redrawnDifferentSurface(), 200),
        ]);

        $item = $this->runItem(
            [
                'framing_preset'      => 'women/skirts',
                'accept_recut_redraw' => true,
            ],
            ['product' => null, 'support_type' => null],
            confirmSameGarment: true,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $this->assertSame('ghost_redraw_kept', $item->apparel_mode_applied,
            'Gemini confirmed the same garment, but the redraw was still discarded');

        $this->assertStringContainsString('confirmed', (string) $item->error_message,
            'the note should say the redraw was checked, not just kept');
    }

    /**
     * The other half of the same case: Gemini looks and cannot confirm it is
     * the same garment (or the same for the purposes of this test — cannot be
     * reached at all), and the redraw is refused exactly as it was before
     * this existed.
     */
    public function test_a_redrawn_verdict_is_refused_when_gemini_cannot_confirm_the_same_garment(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->redrawnDifferentSurface(), 200),
        ]);

        $item = $this->runItem(
            [
                'framing_preset'      => 'women/skirts',
                'accept_recut_redraw' => true,
            ],
            ['product' => null, 'support_type' => null],
            confirmSameGarment: false,
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $this->assertNotSame('ghost_redraw_kept', $item->apparel_mode_applied,
            'an unconfirmed redraw was published anyway');

        $this->assertStringContainsString('could not be confirmed', (string) $item->error_message);
    }

    /**
     * Without the checkbox, a 'redrawn' verdict is refused the old way and
     * Gemini is never asked at all — asking would be a real API call spent on
     * a photo that was never a candidate for "keep the redraw" in the first
     * place. The mocked Gemini in runItem() has no expectation set for
     * confirmSameGarment() by default, so a call here fails the test loudly
     * rather than silently passing.
     */
    public function test_a_redrawn_verdict_without_the_checkbox_never_asks_gemini(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response($this->redrawnDifferentSurface(), 200),
        ]);

        $item = $this->runItem(
            ['framing_preset' => 'women/skirts'],
            ['product' => null, 'support_type' => null],
        );

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertNotSame('ghost_redraw_kept', $item->apparel_mode_applied);
    }

    /**
     * A kept redraw has to say so on screen.
     *
     * ghost_redraw_kept had no entry in the show page's label map, so it fell
     * through to the same "cutout only" default an ordinary, untouched cutout
     * gets — on the one mode where the whole picture is Photoroom's redraw and
     * checking the print actually matters. The operator had no way to tell
     * this image apart from a real photograph without opening the error text
     * underneath it.
     */
    public function test_a_kept_redraw_is_labelled_as_one_on_the_show_page(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
        ]);

        PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => 'edited',
            'apparel_mode_applied'  => 'ghost_redraw_kept',
        ]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.show', $session))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ghost_redraw_kept', $html);
        $this->assertStringContainsString('redrawn · check the print', $html);
    }
}
