<?php

namespace Tests\Feature;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Category framing: every dress in the same place on the canvas.
 *
 * The photos in one category arrive from different shoots, cropped differently,
 * and a collection page shows them beside each other — so the thing that makes
 * the page look bought-from-one-brand is not the cutout, it is the product
 * landing on the same spot every time. What is asserted here is that a category
 * decides the framing outright: the same numbers whatever the run was set to
 * before, whatever the form posts alongside it, and whatever framing the SKU
 * was carrying from an earlier configuration.
 */
class PhotoEditorFramingTest extends TestCase
{
    use RefreshDatabase;

    /** The Photoroom fields that decide where the product sits. */
    private const LAYOUT_KEYS = [
        'outputSize', 'padding', 'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight',
        'margin', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight',
        'horizontalAlignment', 'verticalAlignment', 'scaling', 'referenceBox',
        'maxWidth', 'maxHeight', 'ignorePaddingAndSnapOnCroppedSides',
    ];

    private function makeSession(array $edits = []): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true, 'perm_photo_editor' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => array_merge(['remove_background' => true, 'background_mode' => 'white'], $edits),
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ]);
    }

    private function group(PhotoEditSession $session, string $sku, ?array $edits = null): PhotoEditGroup
    {
        PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => strtolower($sku) . '.jpg',
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $sku,
        ]);

        return PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => $sku,
            'edits'                 => $edits,
        ]);
    }

    /** The layout half of what Photoroom is actually asked for. */
    private function layoutFields(array $edits): array
    {
        $fields = app(PhotoroomService::class)->buildFields($edits);

        return array_intersect_key($fields, array_flip(self::LAYOUT_KEYS));
    }

    public function test_a_category_decides_the_framing_whatever_the_form_posts_beside_it(): void
    {
        Queue::fake();

        $session = $this->makeSession(['padding' => 0.3, 'h_align' => 'left']);
        $group   = $this->group($session, 'DRESS-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            // A stale slider and a stale alignment, exactly what a browser
            // posts when it fills the boxes in and then locks them.
            'edits'  => [
                'framing_preset' => 'women/dresses',
                'padding'        => '0.3',
                'h_align'        => 'left',
                'padding_top'    => '400',
            ],
            'groups' => [$group->id => ['lifestyle_count' => 0]],
        ])->assertRedirect(route('photo-editor.show', $session));

        $edits = $session->fresh()->edits;

        $this->assertSame('women/dresses', $edits['framing_preset']);
        $this->assertEquals(2000, $edits['width']);
        $this->assertEquals(2000, $edits['height']);
        $this->assertEquals(0.06, $edits['padding']);
        $this->assertSame('center', $edits['h_align']);
        $this->assertSame('center', $edits['v_align']);
        $this->assertSame('fit', $edits['scaling']);
        $this->assertSame('subjectBox', $edits['reference_box']);

        // The per-edge override is the one that quietly breaks a row of
        // dresses, so a category has to clear it rather than merge past it.
        $this->assertNull($edits['padding_top']);
    }

    /** Two SKUs, two histories, one category: the same request to Photoroom. */
    public function test_two_skus_in_one_category_are_framed_identically(): void
    {
        Queue::fake();

        $session = $this->makeSession();

        // One SKU was framed tight by hand last time round, the other loose
        // and bottom-aligned. Both are now told they are women's dresses.
        $tight = $this->group($session, 'DRESS-1', ['padding' => 0, 'padding_bottom' => '250px', 'v_align' => 'bottom']);
        $loose = $this->group($session, 'DRESS-2', ['padding' => 0.35, 'scaling' => 'fill', 'reference_box' => 'originalImage']);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $tight->id => ['differs' => '1', 'edits' => ['framing_preset' => 'women/dresses']],
                $loose->id => ['differs' => '1', 'edits' => ['framing_preset' => 'women/dresses']],
            ],
        ])->assertRedirect(route('photo-editor.show', $session));

        $this->assertSame(
            $this->layoutFields($tight->fresh()->edits),
            $this->layoutFields($loose->fresh()->edits),
            'two SKUs in the same category asked Photoroom for different framing',
        );

        // And the framing is the standard's, not either SKU's old one.
        $this->assertSame([
            'outputSize'          => '2000x2000',
            'padding'             => '0.06',
            'horizontalAlignment' => 'center',
            'verticalAlignment'   => 'center',
            'scaling'             => 'fit',
        ], $this->layoutFields($tight->fresh()->edits));
    }

    /** A run says which category it was held to, both levels of it. */
    public function test_a_run_reports_the_category_it_was_held_to(): void
    {
        $session = $this->makeSession(
            PhotoroomService::applyFramingPreset(PhotoroomService::defaultEdits(), 'women/dresses'),
        );

        $this->assertStringContainsString('Women → Dresses framing', $session->editSummary());
    }

    /** Footwear stands on a line; a dress hangs in the middle. */
    public function test_categories_frame_their_own_shape(): void
    {
        $dress = PhotoroomService::applyFramingPreset([], 'women/dresses');
        $shoes = PhotoroomService::applyFramingPreset([], 'women/footwear');

        $this->assertSame('center', $this->layoutFields($dress)['verticalAlignment']);
        $this->assertSame('bottom', $this->layoutFields($shoes)['verticalAlignment']);
    }

    /** Framing by hand is still allowed, and is not labelled as a standard. */
    public function test_framing_by_hand_keeps_what_was_typed(): void
    {
        Queue::fake();

        $session = $this->makeSession(['framing_preset' => 'women/dresses', 'padding' => 0.06]);
        $group   = $this->group($session, 'DRESS-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'edits'  => ['framing_preset' => '', 'padding' => '0.2', 'v_align' => 'top'],
            'groups' => [$group->id => ['lifestyle_count' => 0]],
        ]);

        $edits = $session->fresh()->edits;

        $this->assertNull($edits['framing_preset']);
        $this->assertEquals(0.2, $edits['padding']);
        $this->assertSame('top', $edits['v_align']);
    }

    /**
     * Every category's framing, pinned to what its sample measured.
     *
     * The readings are the whole value of this table, and nothing on the screen
     * would show if one drifted — a dress quietly moving from 6% to 10% looks
     * like a dress either way until it is sitting next to the twelve that
     * did not move. So the numbers are asserted rather than trusted, and
     * changing one means changing it here too, deliberately.
     */
    public function test_each_category_keeps_the_padding_its_sample_measured(): void
    {
        $measured = [
            'women/dresses'   => ['0.06', 'center'],
            'women/gown'      => ['0.08', 'center'],
            'women/top'       => ['0.1',  'center'],
            'women/t-shirt'   => ['0.1',  'center'],
            'women/blazer'    => ['0.1',  'center'],
            'women/jeans'     => ['0.1',  'center'],
            'women/skirts'    => ['0.1',  'center'],
            'women/swim-wear' => ['0.1',  'center'],
            'women/bras'      => ['0.17', 'center'],
            'women/bags'      => ['0.18', 'bottom'],
            'women/belts'     => ['0.11', 'center'],
            'women/footwear'  => ['0.07', 'bottom'],
        ];

        foreach ($measured as $key => [$padding, $valign]) {
            $fields = $this->layoutFields(PhotoroomService::applyFramingPreset([], $key));

            $this->assertSame($padding, $fields['padding'], "{$key} has drifted off its measurement");
            $this->assertSame($valign, $fields['verticalAlignment'], "{$key} no longer sits where it was measured");
        }

        // Every womenswear subcategory is accounted for, so a new one cannot be
        // added without a reading to go with it. Other departments are covered
        // by the canvas and baseline tests rather than pinned figure by figure,
        // because only womenswear has been sampled across the board.
        $women = array_filter(array_keys(PhotoroomService::framingPresetsFlat()),
            fn ($key) => str_starts_with($key, 'women/'));

        $this->assertSame([], array_diff($women, array_keys($measured)),
            'a womenswear category exists with no measurement behind it');
    }

    /**
     * Beauty was measured, not inherited.
     *
     * The request was to reuse perfume's framing. The samples say otherwise:
     * a YSL concealer sits at 8.6% and fills 82.8%, where perfume sits at
     * 10.3%/10.6% and fills 79.1%. Copying perfume across would have framed
     * every beauty product about 2% smaller than the catalogue does, which is
     * the same mistake the perfume preset itself made twice by carrying a
     * percentage over from a canvas it was not measured on.
     */
    public function test_beauty_keeps_its_own_measurement_rather_than_perfumes(): void
    {
        $beauty  = $this->layoutFields(PhotoroomService::applyFramingPreset([], 'beauty/makeup'));
        $perfume = $this->layoutFields(PhotoroomService::applyFramingPreset([], 'perfume'));

        $this->assertSame('0.086', $beauty['padding'] ?? null);
        $this->assertNotSame($perfume['padding'] ?? null, $beauty['padding'] ?? null,
            'beauty has quietly been given perfume\'s framing');
    }

    /**
     * Both shots of the sample product agree on 8.6%, one bound by its height
     * and one by its width — so the preset has to be a single padding applied
     * to whichever edge binds, not a height rule.
     */
    public function test_beauty_is_centred_on_both_axes(): void
    {
        $fields = $this->layoutFields(PhotoroomService::applyFramingPreset([], 'beauty/makeup'));

        $this->assertSame('center', $fields['verticalAlignment'] ?? null);
        $this->assertSame('center', $fields['horizontalAlignment'] ?? null);
        $this->assertArrayNotHasKey('paddingBottom', $fields,
            'a swatch photographed flat has no bottom edge to stand on');
    }

    /** Skin care follows makeup until somebody measures a jar. */
    public function test_skincare_follows_makeup(): void
    {
        $makeup   = $this->layoutFields(PhotoroomService::applyFramingPreset([], 'beauty/makeup'));
        $skincare = $this->layoutFields(PhotoroomService::applyFramingPreset([], 'beauty/skincare'));

        $this->assertSame($makeup['padding'] ?? null, $skincare['padding'] ?? null);
        $this->assertSame($makeup['outputSize'] ?? null, $skincare['outputSize'] ?? null);
    }

    /**
     * The categories that stand on a line, and only those.
     *
     * Two kinds of product need one. A bag category holds a flat clutch and a
     * tall top-handle bag; a perfume category holds a squat bottle and a slim
     * one. Fit each to the canvas and they come out different heights whatever
     * the padding, so only a shared bottom edge makes a row of them read as a
     * row. A garment category has no such problem — every dress is taller than
     * it is wide, so the padding is the base line.
     *
     * Asserted as a closed list because an override is a decision. One
     * appearing on a category that never asked for it would move that
     * category's products off the line every other one stands on, and nothing
     * on the screen would say so.
     */
    public function test_only_the_categories_that_stand_on_a_line_have_a_baseline(): void
    {
        $baselines = [
            'women/bags'     => '0.2',    // three bags measured 19.8%, 19.9%, 18.6%
            'perfume'         => '0.106', // measured off a live 2000x2000 catalogue shot
        ];

        foreach ($baselines as $key => $expected) {
            $fields = $this->layoutFields(PhotoroomService::applyFramingPreset([], $key));

            $this->assertSame($expected, $fields['paddingBottom'] ?? null,
                "{$key} lost the base line it was measured on");
            $this->assertSame('bottom', $fields['verticalAlignment'],
                "{$key} has a base line but is not standing on it");
        }

        foreach (array_keys(PhotoroomService::framingPresetsFlat()) as $key) {
            if (isset($baselines[$key])) {
                continue;
            }

            $this->assertArrayNotHasKey('paddingBottom',
                $this->layoutFields(PhotoroomService::applyFramingPreset([], $key)),
                "{$key} has a per-edge override nobody declared");
        }
    }

    /**
     * One canvas for the whole website, whatever the category.
     *
     * Padding is what differs between categories; the canvas is what stops
     * them differing. Two masters at different sizes render the same in a grid
     * tile, so a stray canvas would not show there — it would show later, in
     * whatever reads the file itself.
     */
    public function test_every_category_shares_one_canvas_and_fits_the_product(): void
    {
        foreach (array_keys(PhotoroomService::framingPresetsFlat()) as $key) {
            $fields = $this->layoutFields(PhotoroomService::applyFramingPreset([], $key));

            $this->assertSame('2000x2000', $fields['outputSize'], "{$key} is not on the standard canvas");
            $this->assertSame('fit', $fields['scaling'], "{$key} does not fit the product to the canvas");
            $this->assertSame('center', $fields['horizontalAlignment'], "{$key} is not centred horizontally");
        }
    }

    /**
     * A key we do not know is not a standard, so it is not stored as one.
     *
     * "women_dresses" is deliberate: it is the flat key this table used before
     * it grew a main-category level, and a run holding one has to fall back to
     * its own framing rather than resolve to something near it.
     */
    public function test_an_unknown_category_is_dropped_rather_than_trusted(): void
    {
        foreach (['mens_hats', 'women_dresses', 'women/', 'women'] as $key) {
            $edits = PhotoroomService::applyFramingPreset(['padding' => 0.2], $key);

            $this->assertNull($edits['framing_preset'], "{$key} resolved to a standard it should not have");
            $this->assertEquals(0.2, $edits['padding']);
        }

        $edits = PhotoroomService::applyFramingPreset(['padding' => 0.2], 'mens_hats');

        $this->assertNull($edits['framing_preset']);
        $this->assertEquals(0.2, $edits['padding']);
    }

    /**
     * An on-model shot lands on the same canvas as the photographs.
     *
     * Generation sizes itself from its own preset, below the catalogue's 2000
     * square, and outputSize cannot correct it — applyLayout deliberately
     * withholds outputSize from a generated canvas because forcing one shape
     * into another is what produces a stretched result. Photoroom's upscaler is
     * the route, and it has to be asked for explicitly, so this pins that it is.
     */
    public function test_an_on_model_shot_is_brought_up_to_the_catalogue_canvas(): void
    {
        $method = new \ReflectionMethod(\App\Jobs\GenerateLifestyleImageJob::class, 'onModelEdits');
        $method->setAccessible(true);

        $edits = $method->invoke(
            new \App\Jobs\GenerateLifestyleImageJob(1, 0),
            ['apparel_size' => 'SQUARE_HD'],
        );

        $fields = app(PhotoroomService::class)->buildFields($edits);

        $this->assertSame('ai.auto', $fields['upscale.mode'] ?? null,
            'an on-model shot is left at whatever size generation chose');

        // "widthxheight", not a bare number — Photoroom rejects "2000".
        $this->assertSame('2000x2000', $fields['upscale.targetResolution'] ?? null);

        // Still withheld: it is the upscaler's job, not the canvas's.
        $this->assertArrayNotHasKey('outputSize', $fields);

        // And fetched losslessly, like every other image.
        $this->assertSame('png', $fields['export.format']);
    }

    /**
     * A refused upscale costs the size, not the image.
     *
     * Photoroom does not document whether its upscaler can be combined with
     * generation, and it has answered no. So the job asks, and on a rejection
     * that names upscale it asks again without it — an on-model shot at the
     * size generation chose is worth having. Anything else must still fail
     * loudly: a quota problem is not fixed by a second identical request, and
     * finding that out would cost another credit.
     */
    public function test_a_refused_upscale_still_returns_an_on_model_shot(): void
    {
        $method = new \ReflectionMethod(\App\Jobs\GenerateLifestyleImageJob::class, 'generate');
        $method->setAccessible(true);

        $job = new \App\Jobs\GenerateLifestyleImageJob(1, 0);

        // Refuses anything carrying an upscale, accepts what is left.
        $fussy = new class extends PhotoroomService {
            public array $attempts = [];

            public function edit(string $imageContent, array $edits, string $filename = 'image.jpg'): string
            {
                $this->attempts[] = array_key_exists('upscale', $edits);

                if (!empty($edits['upscale'])) {
                    throw new \RuntimeException('Photoroom returned 400: upscale/mode must be equal to one of the allowed values');
                }

                return 'generated-bytes';
            }
        };

        $this->assertSame('generated-bytes', $method->invoke($job, $fussy, 'in', [], 'a.jpg'));
        $this->assertSame([true, false], $fussy->attempts,
            'the upscale was not asked for first, or not given up on second');

        // A failure that has nothing to do with upscale is not swallowed.
        $broken = new class extends PhotoroomService {
            public function edit(string $imageContent, array $edits, string $filename = 'image.jpg'): string
            {
                throw new \RuntimeException('Photoroom quota is exhausted — available again in 4 hours.');
            }
        };

        $this->expectException(\RuntimeException::class);
        $method->invoke($job, $broken, 'in', [], 'a.jpg');
    }

    /**
     * Every category can say what its product is called.
     *
     * A garment on a hanger has two routes through Photoroom: name the product
     * and the stand is cut out of the real photograph in one request, or leave
     * it blank and a generative pass erases the stand and redraws the garment —
     * an extra credit, and it has come back reshaped. The category already
     * knows the answer, so a missing noun here is a photo that quietly takes
     * the expensive, destructive route.
     */
    public function test_every_category_can_name_its_product(): void
    {
        $categories = array_keys(PhotoroomService::framingPresetsFlat());

        $this->assertSame([], array_diff($categories, array_keys(PhotoroomService::PRODUCT_NOUNS)),
            'a category exists that cannot say what its product is called');

        $this->assertSame('the t-shirt', PhotoroomService::productNoun('women/t-shirt'));
        $this->assertNull(PhotoroomService::productNoun('mens_hats'));
        $this->assertNull(PhotoroomService::productNoun(null));
    }

    /**
     * Naming the product is what keeps the cutout out of the generative pass.
     *
     * The segmentation fields have to reach Photoroom on their own, without
     * the AI-cleanup treatment being switched on — that treatment is the thing
     * that redraws, and the whole point of naming the product is to avoid it.
     */
    public function test_naming_the_product_segments_without_the_generative_pass(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background'            => true,
            'background_mode'              => 'white',
            'ghost_mannequin'              => false,
            'segmentation_prompt'          => 'the t-shirt',
            'segmentation_negative_prompt' => 'the hanger',
        ]);

        $this->assertSame('the t-shirt', $fields['segmentation.prompt'] ?? null,
            'the product name never reached Photoroom');
        $this->assertSame('the hanger', $fields['segmentation.negativePrompt'] ?? null);

        // Nothing here asks for a garment to be redrawn.
        $this->assertArrayNotHasKey('apparel.mode', $fields);

        /*
         * And saliency is stood down. Left to itself the model protects what it
         * judges to be the main subject, which on a garment hanging up takes in
         * the hanger — the negative prompt then loses and the stand survives,
         * which is the one thing naming the product was meant to prevent.
         */
        $this->assertSame('ignoreSalientObject', $fields['segmentation.mode'] ?? null,
            'saliency was left to overrule the description');
    }

    /** An explicitly chosen mode is still the operator's to choose. */
    public function test_a_chosen_segmentation_mode_is_not_overridden(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background'   => true,
            'segmentation_prompt' => 'the t-shirt',
            'segmentation_mode'   => 'keepSalientObject',
        ]);

        $this->assertSame('keepSalientObject', $fields['segmentation.mode']);
    }

    /** No description means nothing to segment by, so no mode either. */
    public function test_no_product_name_means_no_segmentation_at_all(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'background_mode'   => 'white',
        ]);

        $this->assertArrayNotHasKey('segmentation.mode', $fields);
        $this->assertArrayNotHasKey('segmentation.prompt', $fields);
    }

    /**
     * The HD cutout header and a named product cannot travel together.
     *
     * Photoroom refuses the pair with a 400 — it decides the subject either
     * from the prompt or from its own HD matting, and asked for both it does
     * neither. The prompt has to win: it is what keeps a hanger out of shot
     * without redrawing the garment, where the header only sharpens an edge.
     */
    public function test_the_hd_header_gives_way_to_a_named_product(): void
    {
        $service = app(PhotoroomService::class);
        $cutout  = ['remove_background' => true, 'background_mode' => 'white'];

        $this->assertArrayHasKey('pr-hd-background-removal', $service->buildHeaders($cutout),
            'a plain cutout lost its HD edge for no reason');

        $this->assertArrayNotHasKey(
            'pr-hd-background-removal',
            $service->buildHeaders($cutout + ['segmentation_prompt' => 'the t-shirt']),
            'the HD header rides along with a named product, which Photoroom refuses',
        );

        // A blank name is not a name, so it must not cost the HD edge.
        $this->assertArrayHasKey(
            'pr-hd-background-removal',
            $service->buildHeaders($cutout + ['segmentation_prompt' => '   ']),
        );
    }

    /** The picker has to be on the screen the framing is chosen on. */
    public function test_the_categories_are_offered_on_the_configure_screen(): void
    {
        $session = $this->makeSession();
        $this->group($session, 'DRESS-1');

        $this->actingAs($session->user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->assertSee('Women')
            ->assertSee('Dresses')
            ->assertSee('Kids &amp; Baby', false)
            ->assertSee('Frame by hand');
    }
}
