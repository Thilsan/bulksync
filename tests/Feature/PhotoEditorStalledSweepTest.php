<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * A photograph abandoned by a stopped worker does not sit on the screen for
 * three hours saying "editing".
 *
 * A job that throws marks its own item failed. A worker killed outright does
 * not, because failed() never runs — and the job then sits reserved in Redis
 * for retry_after, which is three hours on this queue. Nothing came to collect
 * it and nothing said so.
 *
 * Marked failed rather than re-queued on purpose: the worker may have died
 * after the Photoroom call and before the save, so a re-run can spend a credit
 * on a photograph that was already edited. Failed items are selectable on the
 * review screen, which puts that decision in front of a person.
 */
class PhotoEditorStalledSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the scheduled closure itself, found by the name it was given.
     *
     * Going through the scheduler rather than copying the logic into the test
     * is the point: a sweeper that works but was never registered, or was
     * registered on a schedule nobody runs, is the same as no sweeper.
     */
    private function sweep(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($e) => str_contains((string) $e->description, 'fail-stalled-photo-edits'));

        $this->assertNotNull($event, 'the stalled-edit sweep is not on the schedule');

        $callback = (new \ReflectionProperty($event, 'callback'))->getValue($event);

        $this->app->call($callback);
    }

    private function item(string $status, int $minutesAgo): PhotoEditItem
    {
        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => 'a.jpg',
            'sku_detected'          => 'SKU-1',
            'status'                => $status,
            'onedrive_drive_id'     => 'd',
            'onedrive_item_id'      => 'i' . uniqid(),
        ]);

        $item->timestamps = false;
        $item->updated_at = Carbon::now()->subMinutes($minutesAgo);
        $item->save();

        return $item;
    }

    public function test_an_edit_abandoned_a_quarter_of_an_hour_ago_is_marked_failed(): void
    {
        $item = $this->item('editing', 20);

        $this->sweep();

        $this->assertSame('failed', $item->fresh()->status);
        $this->assertStringContainsString('run it again', $item->fresh()->error_message);
    }

    /**
     * A redraw still running is left alone.
     *
     * The job's own timeout is five minutes, so fifteen is three times longer
     * than anything that is genuinely working.
     */
    public function test_an_edit_still_running_is_left_alone(): void
    {
        $item = $this->item('editing', 4);

        $this->sweep();

        $this->assertSame('editing', $item->fresh()->status);
    }

    /** And nothing else is touched, whatever state it is in. */
    public function test_it_touches_nothing_but_stalled_edits(): void
    {
        $pending = $this->item('pending', 90);
        $edited  = $this->item('edited', 90);

        $this->sweep();

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('edited', $edited->fresh()->status);
    }

    /** The run's own counts follow, so the tiles above the grid agree with it. */
    public function test_the_runs_failed_count_is_brought_up_to_date(): void
    {
        $item = $this->item('editing', 30);

        $this->sweep();

        $this->assertSame(1, PhotoEditSession::find($item->photo_edit_session_id)->failed_files);
    }
}
