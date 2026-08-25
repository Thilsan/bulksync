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
                'framing_preset' => 'women_dresses',
                'padding'        => '0.3',
                'h_align'        => 'left',
                'padding_top'    => '400',
            ],
            'groups' => [$group->id => ['lifestyle_count' => 0]],
        ])->assertRedirect(route('photo-editor.show', $session));

        $edits = $session->fresh()->edits;

        $this->assertSame('women_dresses', $edits['framing_preset']);
        $this->assertEquals(2048, $edits['width']);
        $this->assertEquals(2048, $edits['height']);
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
                $tight->id => ['differs' => '1', 'edits' => ['framing_preset' => 'women_dresses']],
                $loose->id => ['differs' => '1', 'edits' => ['framing_preset' => 'women_dresses']],
            ],
        ])->assertRedirect(route('photo-editor.show', $session));

        $this->assertSame(
            $this->layoutFields($tight->fresh()->edits),
            $this->layoutFields($loose->fresh()->edits),
            'two SKUs in the same category asked Photoroom for different framing',
        );

        // And the framing is the standard's, not either SKU's old one.
        $this->assertSame([
            'outputSize'          => '2048x2048',
            'padding'             => '0.06',
            'horizontalAlignment' => 'center',
            'verticalAlignment'   => 'center',
            'scaling'             => 'fit',
        ], $this->layoutFields($tight->fresh()->edits));
    }

    /** Shoes stand on a line; dresses hang in the middle. */
    public function test_categories_frame_their_own_shape(): void
    {
        $dress = PhotoroomService::applyFramingPreset([], 'women_dresses');
        $shoes = PhotoroomService::applyFramingPreset([], 'shoes');

        $this->assertSame('center', $this->layoutFields($dress)['verticalAlignment']);
        $this->assertSame('bottom', $this->layoutFields($shoes)['verticalAlignment']);
    }

    /** Framing by hand is still allowed, and is not labelled as a standard. */
    public function test_framing_by_hand_keeps_what_was_typed(): void
    {
        Queue::fake();

        $session = $this->makeSession(['framing_preset' => 'women_dresses', 'padding' => 0.06]);
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

    /** A key we do not know is not a standard, so it is not stored as one. */
    public function test_an_unknown_category_is_dropped_rather_than_trusted(): void
    {
        $edits = PhotoroomService::applyFramingPreset(['padding' => 0.2], 'mens_hats');

        $this->assertNull($edits['framing_preset']);
        $this->assertEquals(0.2, $edits['padding']);
    }

    /** The picker has to be on the screen the framing is chosen on. */
    public function test_the_categories_are_offered_on_the_configure_screen(): void
    {
        $session = $this->makeSession();
        $this->group($session, 'DRESS-1');

        $this->actingAs($session->user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->assertSee("Women's dresses")
            ->assertSee('Frame by hand');
    }
}
