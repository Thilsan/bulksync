<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Jobs\GenerateLifestyleImageJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The step between finding photos and paying for them.
 *
 * A run mixes product types that want opposite treatment, so settings belong
 * to each SKU folder rather than to the run. The property that matters most
 * here is that nothing is queued — and so nothing is billed — until somebody
 * has looked at what was found and pressed start.
 */
class PhotoEditorConfigureTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    private function makeSession(array $attributes = []): PhotoEditSession
    {
        return PhotoEditSession::create(array_merge([
            'user_id'       => $this->editor()->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true, 'background_mode' => 'white'],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ], $attributes));
    }

    private function photo(PhotoEditSession $session, string $sku, string $filename): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => $filename,
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $filename,
        ]);
    }

    private function group(PhotoEditSession $session, string $sku): PhotoEditGroup
    {
        return PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => $sku,
            'edits'                 => $session->edits,
        ]);
    }

    /** Scanning finds the photos; it must not spend anything on them. */
    public function test_a_scanned_session_queues_nothing_until_it_is_started(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $this->group($session, 'SKU-1');

        $this->actingAs($session->user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->assertSee('SKU-1');

        Queue::assertNothingPushed();
    }

    /** Each SKU keeps its own settings, not the run's. */
    public function test_settings_are_saved_per_sku(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'DRESS', 'a.jpg');
        $this->photo($session, 'WATCH', 'b.jpg');
        $dress = $this->group($session, 'DRESS');
        $watch = $this->group($session, 'WATCH');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $dress->id => ['edits' => ['padding' => '0.12', 'background_mode' => 'white']],
                $watch->id => ['edits' => ['padding' => '0',    'background_mode' => 'transparent']],
            ],
        ])->assertRedirect(route('photo-editor.show', $session));

        // assertEquals, not assertSame: edits round-trip through JSON, and a
        // zero comes back as an int however it went in.
        $this->assertEquals(0.12, $dress->fresh()->edits['padding']);
        $this->assertSame('transparent', $watch->fresh()->edits['background_mode']);
        $this->assertEquals(0, $watch->fresh()->edits['padding']);
    }

    /** Starting the run is what queues the work. */
    public function test_starting_queues_one_edit_per_photo(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $this->photo($session, 'SKU-1', 'b.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['lifestyle_count' => 0]],
        ]);

        Queue::assertPushed(EditPhotoItemJob::class, 2);
        Queue::assertNotPushed(GenerateLifestyleImageJob::class);
        $this->assertSame('processing', $session->fresh()->status);
    }

    /** On-model images are extra rows, extra jobs and extra credits. */
    public function test_lifestyle_images_are_generated_from_the_chosen_photo(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $front   = $this->photo($session, 'SKU-1', 'front.jpg');
        $this->photo($session, 'SKU-1', 'detail.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'lifestyle_count'          => 3,
                'lifestyle_source_item_id' => $front->id,
            ]],
        ]);

        Queue::assertPushed(GenerateLifestyleImageJob::class, 3);

        $generated = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'lifestyle')->get();

        $this->assertCount(3, $generated);
        $this->assertTrue($generated->every(fn ($i) => $i->source_item_id === $front->id));

        // Generated imagery is opted into, never pushed by default.
        $this->assertTrue($generated->every(fn ($i) => !$i->selected));
    }

    /** A count with no photo to dress the model from can only fail later. */
    public function test_lifestyle_without_a_source_photo_is_refused(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['lifestyle_count' => 2]],
            ])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** A run cannot be started twice and billed twice. */
    public function test_a_started_run_cannot_be_started_again(): void
    {
        Queue::fake();

        $session = $this->makeSession(['status' => 'processing']);
        $this->photo($session, 'SKU-1', 'a.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['lifestyle_count' => 0]],
            ])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** An item edits with its own group's settings, not the run's. */
    public function test_an_item_resolves_its_own_groups_settings(): void
    {
        $session = $this->makeSession();
        $item    = $this->photo($session, 'WATCH', 'a.jpg');

        PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'WATCH',
            'edits'                 => ['padding' => 0.4, 'background_mode' => 'transparent'],
        ]);

        $this->assertSame(0.4, $item->resolvedEdits()['padding']);
        $this->assertSame('transparent', $item->resolvedEdits()['background_mode']);
    }

    /** Runs created before groups existed still edit with the run's settings. */
    public function test_an_item_without_a_group_falls_back_to_the_session(): void
    {
        $session = $this->makeSession();
        $item    = $this->photo($session, 'ORPHAN', 'a.jpg');

        $this->assertSame('white', $item->resolvedEdits()['background_mode']);
    }

    /**
     * The size, padding and framing fields are the ones a mixed run most needs
     * to differ on — a watch face wants none of the padding a dress does — so
     * they are guarded end to end rather than only in the markup.
     */
    public function test_size_padding_and_framing_save_per_sku(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'DRESS', 'a.jpg');
        $this->photo($session, 'WATCH', 'b.jpg');
        $dress = $this->group($session, 'DRESS');
        $watch = $this->group($session, 'WATCH');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $dress->id => ['edits' => [
                    'width' => '2048', 'height' => '2048', 'padding' => '0.1',
                    'h_align' => 'center', 'v_align' => 'center',
                    'scaling' => 'fit', 'reference_box' => 'subjectBox',
                ]],
                $watch->id => ['edits' => [
                    'width' => '1000', 'height' => '1000', 'padding' => '0',
                    'h_align' => 'center', 'v_align' => 'bottom',
                    'scaling' => 'fill', 'reference_box' => 'originalImage',
                ]],
            ],
        ]);

        $d = $dress->fresh()->edits;
        $w = $watch->fresh()->edits;

        $this->assertSame(2048, $d['width']);
        $this->assertEquals(0.1, $d['padding']);
        $this->assertSame('fit', $d['scaling']);

        $this->assertSame(1000, $w['width']);
        $this->assertEquals(0, $w['padding']);
        $this->assertSame('bottom', $w['v_align']);
        $this->assertSame('fill', $w['scaling']);
        $this->assertSame('originalImage', $w['reference_box']);
    }

    /** What the group stores is what Photoroom is told. */
    public function test_group_size_and_padding_reach_photoroom(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'width'             => 2048,
            'height'            => 2048,
            'padding'           => 0.1,
            'h_align'           => 'center',
            'scaling'           => 'fit',
        ]);

        $this->assertSame('2048x2048', $fields['outputSize']);
        $this->assertSame('0.1', $fields['padding']);
        $this->assertSame('center', $fields['horizontalAlignment']);
        $this->assertSame('fit', $fields['scaling']);
    }

    /**
     * A shoe photographed on its sole wants headroom above and almost none
     * below, or it floats in the frame. The even slider cannot express that,
     * so per-edge overrides have to survive the round trip to Photoroom.
     */
    public function test_per_edge_padding_saves_and_reaches_photoroom(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SHOE', 'a.jpg');
        $shoe = $this->group($session, 'SHOE');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$shoe->id => ['edits' => [
                'width' => '2000', 'height' => '2000',
                'padding_top' => '180', 'padding_bottom' => '40',
                'v_align' => 'bottom', 'scaling' => 'fit',
            ]]],
        ]);

        $edits = $shoe->fresh()->edits;

        // Typed in pixels and stored with its unit, so nothing downstream has
        // to infer what a bare number meant.
        $this->assertSame('180px', $edits['padding_top']);
        $this->assertSame('bottom', $edits['v_align']);

        $fields = app(\App\Services\PhotoroomService::class)->buildFields(
            array_merge($edits, ['remove_background' => true]),
        );

        $this->assertSame('2000x2000', $fields['outputSize']);
        $this->assertSame('180px', $fields['paddingTop']);
        $this->assertSame('40px', $fields['paddingBottom']);
        $this->assertSame('bottom', $fields['verticalAlignment']);
    }

    /**
     * "10" used to mean 0.49 — a fraction cannot exceed half the canvas, so a
     * typed pixel count was clamped to the maximum and quietly produced 49%
     * padding around a stamp-sized product.
     */
    public function test_a_bare_number_over_one_is_read_as_pixels_not_a_clamped_fraction(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '10',
        ]);

        $this->assertSame('10px', $fields['paddingTop']);
    }

    /** A real fraction still means a fraction. */
    public function test_a_fraction_is_still_a_fraction(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '0.18',
        ]);

        $this->assertSame('0.18', $fields['paddingTop']);
    }

    /** An explicit unit is passed through untouched. */
    public function test_an_explicit_unit_is_respected(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '40px',
            'padding_left'      => '5%',
        ]);

        $this->assertSame('40px', $fields['paddingTop']);
        $this->assertSame('5%',   $fields['paddingLeft']);
    }

    /**
     * The erase target is the same on every apparel shot; the product's own
     * name is not. Pre-filling only the half that generalises is what keeps
     * segmentation off until somebody has actually named the product — running
     * it on a guess would cut out the wrong thing.
     */
    public function test_segmentation_is_prefilled_but_stays_off_until_the_product_is_named(): void
    {
        $defaults = \App\Services\PhotoroomService::defaultEdits();

        $this->assertNull($defaults['segmentation_prompt']);
        $this->assertStringContainsString('clothes rail', $defaults['segmentation_negative_prompt']);

        // Nothing sent while the product is unnamed.
        $fields = app(\App\Services\PhotoroomService::class)->buildFields(
            array_merge($defaults, ['remove_background' => true]),
        );

        $this->assertArrayNotHasKey('segmentation.prompt', $fields);
        $this->assertArrayNotHasKey('segmentation.negativePrompt', $fields);
    }

    /** Naming the product is what switches segmentation on. */
    public function test_naming_the_product_switches_segmentation_on(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields(
            array_merge(\App\Services\PhotoroomService::defaultEdits(), [
                'remove_background'   => true,
                'segmentation_prompt' => 'the orange polo shirt',
            ]),
        );

        $this->assertSame('the orange polo shirt', $fields['segmentation.prompt']);
        $this->assertStringContainsString('clothes rail', $fields['segmentation.negativePrompt']);
    }

    /**
     * A garment rail holds a scarf up exactly as a dress form holds a dress.
     * The erase pass only ever named mannequins, so a rail-hung item came back
     * with the rail still in the cutout — and the label said "cutout only",
     * which read as nothing having gone wrong.
     */
    public function test_the_erase_prompt_covers_rails_not_just_mannequins(): void
    {
        $service = new \ReflectionClass(\App\Services\PhotoroomService::class);
        $prompt  = $service->getConstant('MANNEQUIN_REMOVAL_PROMPT');

        foreach (['mannequin', 'dress form', 'clothes rail', 'hanger', 'stand'] as $support) {
            $this->assertStringContainsString($support, $prompt);
        }
    }
}
