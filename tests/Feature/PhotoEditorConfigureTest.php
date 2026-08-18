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
}
