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
    private function runItem(array $edits): PhotoEditItem
    {
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
        $gemini->shouldReceive('classifyGarmentView')->andReturn([
            'view_type'         => 'front',
            'mannequin_visible' => true,
            'product'           => 'the cropped top',
            'support'           => 'the hanger',

            // Held, not worn — the case where naming the product is offered at
            // all, and therefore the case where a bad guess has to be caught.
            'support_type'      => 'held',
        ]);

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

    /** The decision under test, run without a queue or an API behind it. */
    private function route(
        array $edits,
        bool $mannequinVisible,
        ?string $seen = null,
        ?string $support = null,
        ?string $supportType = 'held',
    ): array {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(
            new EditPhotoItemJob(1), $edits, $mannequinVisible, $seen, $support, $supportType,
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
     * garment, which the photograph does not contain. Asked to try, the cutout
     * keeps it — a mannequin returned still wearing the poncho after the
     * background came away cleanly, which is what was reported.
     *
     * The operator asked for the stand to go, and only a redraw can do it.
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
}
