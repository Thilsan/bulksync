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
 * Which Photoroom route a photo takes, and what happens to the result.
 *
 * The routing used to be decided by a Gemini read of the photograph before
 * any editing ran — which side was facing, whether a stand was in frame, what
 * the product was. It was wrong often enough that one ticked checkbox produced
 * four different outcomes across two photos of one dress, so it is gone, and
 * with it every test that asserted what it decided.
 *
 * What is left is what the operator set: a typed product goes to a text-guided
 * cutout, a ticked "Remove the stand" goes to Ghost Mannequin, and the redraw
 * that comes back is the image that is kept.
 */
class PhotoEditorNoRedrawTest extends TestCase
{
    use RefreshDatabase;

    /** One item through the real job, with only OneDrive stood in for. */
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

        (new EditPhotoItemJob($item->id))->handle(
            $oneDrive,
            app(\App\Services\ImageProcessingService::class),
            app(PhotoroomService::class),
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
    private function route(array $edits, int $photoWidth = 0, int $photoHeight = 0): array
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, $photoWidth, $photoHeight);
    }

    /**
     * A typed product wins over the redraw, and survives untouched.
     *
     * Nothing guesses a product noun any more, so there is nothing left that
     * could overwrite one — but the word the operator typed still has to
     * reach Photoroom, and still has to take the photo to the cutout rather
     * than the redraw, which is the cheaper route and the one that keeps
     * real pixels.
     */
    public function test_a_typed_product_wins_over_the_redraw(): void
    {
        [$mode, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'segmentation_prompt' => 'the poncho', 'ghost_mannequin' => true],
        );

        $this->assertSame('segmented', $mode);
        $this->assertSame('the poncho', $itemEdits['segmentation_prompt'] ?? null);
        $this->assertFalse((bool) ($itemEdits['ghost_mannequin'] ?? false));
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
        );

        $this->assertSame('SQUARE_HD', $itemEdits['apparel_size'] ?? null);
    }

    /**
     * The redraw is still reachable for a category nobody has given a word to,
     * which is what it was always for.
     */
    public function test_an_unnamed_category_can_still_be_redrawn(): void
    {
        [$mode] = $this->route(
            ['framing_preset' => 'women/gown-unlisted', 'ghost_mannequin' => true],
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
     * A redraw the composite cannot verify is thrown away, and the photograph
     * is published with the stand still in it.
     *
     * This briefly asserted the opposite — keep the redraw whatever the
     * measurement said, because the operator ticked "Remove the stand" and
     * handing back the stand ignores that. A batch of jeans settled it: four
     * cards, all four flagged, proportions changed by 38.6%, 46.6%, 46.8%
     * and 49.0%, two of them with the denim drained to a pale grey. Those
     * are pictures of garments nobody photographed and nobody sells.
     *
     * The note reaches the operator; the image reaches the customer. So a
     * refusal ends the matter, and the cost of that is a visible stand the
     * operator can see and fix — not an invented product they cannot.
     */
    public function test_a_redraw_the_composite_refuses_falls_back_to_the_photograph(): void
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

        $item = $this->runItem(['framing_preset' => 'women/skirts']);

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $this->assertSame('cutout_unnamed', $item->apparel_mode_applied,
            'a redraw the composite refused was published anyway');

        $this->assertStringContainsString('kept as shot', (string) $item->error_message,
            'the operator was not told the stand is still in the picture');

        $this->assertStringContainsString('proportions', (string) $item->error_message,
            'the reason the composite gave should reach the operator');

        // Two requests: the redraw, then the cutout that replaced it. The
        // second credit is the price of publishing the real product.
        $this->assertSame(2, $calls, 'the fallback cutout was not fetched');
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
