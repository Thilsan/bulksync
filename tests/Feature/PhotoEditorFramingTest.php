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
            'women/bags'      => ['0.25', 'bottom'],
            'women/belts'     => ['0.11', 'center'],
            'women/footwear'  => ['0.07', 'bottom'],
        ];

        foreach ($measured as $key => [$padding, $valign]) {
            $fields = $this->layoutFields(PhotoroomService::applyFramingPreset([], $key));

            $this->assertSame($padding, $fields['padding'], "{$key} has drifted off its measurement");
            $this->assertSame($valign, $fields['verticalAlignment'], "{$key} no longer sits where it was measured");
        }

        // Every womenswear subcategory is accounted for, so a new one cannot be
        // added without a reading to go with it.
        $women = array_filter(array_keys(PhotoroomService::framingPresetsFlat()),
            fn ($key) => str_starts_with($key, 'women/'));

        $this->assertSame([], array_diff($women, array_keys($measured)),
            'a womenswear category exists with no measurement behind it');
    }

    /**
     * Bags stand on a measured line, not on the even padding.
     *
     * Two bags of different shapes were measured 19.9% up from the bottom, so
     * the bottom edge is an override and not whatever the scale happened to
     * leave. Lose the override and a clutch and a top-handle bag stop sharing
     * a baseline, which is the one thing a row of bags has to get right.
     */
    public function test_bags_keep_their_baseline_override(): void
    {
        $fields = $this->layoutFields(PhotoroomService::applyFramingPreset([], 'women/bags'));

        $this->assertSame('0.2', $fields['paddingBottom'], 'bags lost the measured baseline');
        $this->assertSame('bottom', $fields['verticalAlignment']);

        // Every other category leaves the bottom edge to the even padding —
        // an override elsewhere would be an accident, not a decision.
        foreach (array_keys(PhotoroomService::framingPresetsFlat()) as $key) {
            if ($key === 'women/bags') {
                continue;
            }

            $this->assertArrayNotHasKey('paddingBottom',
                $this->layoutFields(PhotoroomService::applyFramingPreset([], $key)),
                "{$key} has a per-edge override nobody asked for");
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
        $this->assertSame('2000', $fields['upscale.targetResolution'] ?? null);

        // Still withheld: it is the upscaler's job, not the canvas's.
        $this->assertArrayNotHasKey('outputSize', $fields);

        // And fetched losslessly, like every other image.
        $this->assertSame('png', $fields['export.format']);
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
