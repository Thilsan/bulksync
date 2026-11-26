<?php

namespace Tests\Feature;

use App\Jobs\CopyOriginalPhotoJob;
use App\Jobs\EditPhotoItemJob;
use App\Jobs\GenerateAngleImageJob;
use App\Jobs\GenerateLifestyleImageJob;
use App\Jobs\Middleware\FeedsPhotoLane;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Support\PhotoLane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two people editing at once take turns instead of the second waiting for
 * the first's whole batch.
 */
class PhotoLaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.photoroom.lane_window' => 2]);
        Queue::fake();
    }

    private function editRun(?User $user = null): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => ($user ?? User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]))->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true, 'background_mode' => 'white'],
            'status'        => 'processing',
            'scan_status'   => 'scanned',
        ]);
    }

    /** @return int[] */
    private function photos(PhotoEditSession $session, int $count, array $extra = []): array
    {
        return collect(range(1, $count))->map(fn ($i) => PhotoEditItem::create(array_merge([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => "{$session->id}-{$i}.jpg",
            'status'                => 'pending',
        ], $extra))->id)->all();
    }

    /** Ids of the photos sent to the queue so far, in the order they were sent. */
    private function sent(): array
    {
        return collect(Queue::pushedJobs())->flatten(1)->map(fn ($p) => $p['job']->itemId)->all();
    }

    private function finish(int $itemId): void
    {
        PhotoEditItem::whereKey($itemId)->update(['status' => 'edited']);
        PhotoLane::finished($itemId);
    }

    public function test_only_a_window_of_one_persons_photos_is_in_the_queue(): void
    {
        $ids = $this->photos($this->editRun(), 10);

        PhotoLane::enqueue($ids);

        $this->assertSame([$ids[0], $ids[1]], $this->sent());
        $this->assertSame(8, PhotoEditItem::whereNull('dispatched_at')->count());
    }

    public function test_a_finished_photo_sends_the_next_one(): void
    {
        $ids = $this->photos($this->editRun(), 5);

        PhotoLane::enqueue($ids);
        $this->finish($ids[0]);

        $this->assertSame([$ids[0], $ids[1], $ids[2]], $this->sent());
    }

    public function test_a_second_person_is_not_stuck_behind_the_first_persons_batch(): void
    {
        $a = $this->photos($this->editRun(), 100);
        PhotoLane::enqueue($a);

        $b = $this->photos($this->editRun(), 3);
        PhotoLane::enqueue($b);

        // B's first two went straight in, beside A's two — not after A's 100.
        $this->assertSame([$a[0], $a[1], $b[0], $b[1]], $this->sent());

        // And as A finishes photos, only A's lane refills.
        $this->finish($a[0]);
        $this->assertSame($a[2], last($this->sent()));
    }

    public function test_one_persons_two_runs_share_one_lane(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        PhotoLane::enqueue($this->photos($this->editRun($user), 5));
        PhotoLane::enqueue($this->photos($this->editRun($user), 5));

        $this->assertCount(2, $this->sent());
    }

    public function test_each_kind_of_photo_gets_its_own_job(): void
    {
        config(['services.photoroom.lane_window' => 10]);
        $session = $this->editRun();

        $edit  = $this->photos($session, 1)[0];
        $asIs  = $this->photos($session, 1, ['skip_edit' => true])[0];
        $life  = $this->photos($session, 1, ['kind' => 'lifestyle', 'lane_variant' => '2'])[0];
        $angle = $this->photos($session, 1, ['kind' => 'angle', 'lane_variant' => 'back'])[0];

        // Made before lane_variant existed: the variant is read off the name.
        $oldLife = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'lifestyle',
            'filename' => 'shoe-lifestyle-3.jpg', 'status' => 'pending',
        ])->id;
        $oldAngle = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'angle',
            'filename' => 'shoe-side.jpg', 'status' => 'pending',
        ])->id;

        PhotoLane::enqueue([$edit, $asIs, $life, $angle, $oldLife, $oldAngle]);

        Queue::assertPushed(EditPhotoItemJob::class, fn ($j) => $j->itemId === $edit);
        Queue::assertPushed(CopyOriginalPhotoJob::class, fn ($j) => $j->itemId === $asIs);
        Queue::assertPushed(GenerateLifestyleImageJob::class, fn ($j) => $j->itemId === $life && $j->variationIndex === 2);
        Queue::assertPushed(GenerateAngleImageJob::class, fn ($j) => $j->itemId === $angle && $j->angle === 'back');
        Queue::assertPushed(GenerateLifestyleImageJob::class, fn ($j) => $j->itemId === $oldLife && $j->variationIndex === 2);
        Queue::assertPushed(GenerateAngleImageJob::class, fn ($j) => $j->itemId === $oldAngle && $j->angle === 'side');
    }

    public function test_a_photo_already_in_the_queue_is_never_sent_twice(): void
    {
        $ids = $this->photos($this->editRun(), 4);

        PhotoLane::enqueue($ids);
        PhotoLane::feedAll();
        PhotoLane::feedAll();

        $this->assertSame([$ids[0], $ids[1]], $this->sent());
    }

    public function test_the_scheduled_feed_refills_a_lane_whose_job_never_called_back(): void
    {
        $ids = $this->photos($this->editRun(), 4);
        PhotoLane::enqueue($ids);

        // A worker killed mid-photo: the photo ended, nothing fed the lane.
        PhotoEditItem::whereKey($ids[0])->update(['status' => 'failed']);

        PhotoLane::feedAll();

        $this->assertSame([$ids[0], $ids[1], $ids[2]], $this->sent());
    }

    public function test_a_photo_lost_hours_ago_stops_holding_a_place(): void
    {
        $ids = $this->photos($this->editRun(), 4);
        PhotoLane::enqueue($ids);

        PhotoEditItem::whereIn('id', [$ids[0], $ids[1]])->update(['dispatched_at' => now()->subHours(3)]);

        PhotoLane::feedAll();

        // The lost two are not re-sent; the next two take their places.
        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[3]], $this->sent());
    }

    public function test_the_job_middleware_feeds_the_lane_even_when_the_job_throws(): void
    {
        $ids = $this->photos($this->editRun(), 3);
        PhotoLane::enqueue($ids);
        PhotoEditItem::whereKey($ids[0])->update(['status' => 'failed']);

        try {
            (new FeedsPhotoLane)->handle(new EditPhotoItemJob($ids[0]), fn () => throw new \RuntimeException('Photoroom 500'));
        } catch (\RuntimeException) {
        }

        $this->assertSame($ids[2], last($this->sent()));
    }

    public function test_starting_a_run_goes_through_the_lane(): void
    {
        $session = $this->editRun();
        $session->update(['status' => 'configuring']);
        $ids = $this->photos($session, 5, ['sku_detected' => 'BAG-1']);

        $group = \App\Models\PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'BAG-1',
        ]);

        $session->user->update(['perm_photo_editor' => true]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['lifestyle_count' => 0]],
        ])->assertRedirect();

        $this->assertSame([$ids[0], $ids[1]], $this->sent());
        $this->assertSame(5, PhotoEditItem::whereNotNull('queued_at')->count());
    }
}
