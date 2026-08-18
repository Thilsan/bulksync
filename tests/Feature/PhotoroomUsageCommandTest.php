<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * photoroom:usage answers "how much of today's allowance is gone", which the
 * Photo Editor history page cannot: that page counts images edited over all
 * time, while the quota counts requests made in the last rolling 24 hours.
 */
class PhotoroomUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    private PhotoEditSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.photoroom.api_key' => 'sandbox_test_key']);

        $this->session = PhotoEditSession::create([
            'user_id'       => User::factory()->create()->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
        ]);
    }

    private function item(array $attributes): void
    {
        $item = PhotoEditItem::create(array_merge([
            'photo_edit_session_id' => $this->session->id,
            'filename'              => 'a.jpg',
            'status'                => 'edited',
        ], $attributes));

        if (isset($attributes['updated_at'])) {
            // Model timestamps overwrite whatever create() was given.
            PhotoEditItem::where('id', $item->id)->update(['updated_at' => $attributes['updated_at']]);
        }
    }

    /** The report, with its column padding collapsed so tests read plainly. */
    private function report(): string
    {
        Artisan::call('photoroom:usage');

        return (string) preg_replace('/[ \t]+/', ' ', Artisan::output());
    }

    /** Throttled failures were refused before Photoroom counted them. */
    public function test_throttled_failures_do_not_count_against_the_allowance(): void
    {
        $this->item(['status' => 'edited']);
        $this->item(['status' => 'failed', 'error_message' => 'Photoroom returned 429: Request was throttled. Expected available in 14782 seconds.']);
        $this->item(['status' => 'failed', 'error_message' => 'Photoroom quota is exhausted — available again in 4h 6m.']);

        $report = $this->report();

        $this->assertStringContainsString('Throttled (free, not counted) 2', $report);
        $this->assertStringContainsString('Requests spent 1', $report);
        $this->assertStringContainsString('Sandbox allowance 99 of 100', $report);
    }

    /** A failure that was not a throttle still had to be uploaded to be refused. */
    public function test_other_failures_do_count(): void
    {
        $this->item(['status' => 'failed', 'error_message' => 'Photoroom returned 400: image too small']);

        $report = $this->report();

        $this->assertStringContainsString('Failures that still cost a request 1', $report);
        $this->assertStringContainsString('Requests spent 1', $report);
    }

    /** Mannequin removal is a second request for the same image. */
    public function test_mannequin_removal_is_counted_as_a_second_request(): void
    {
        $this->item(['status' => 'edited', 'apparel_mode_applied' => 'mannequin_removed']);
        $this->item(['status' => 'edited', 'apparel_mode_applied' => 'none']);

        $report = $this->report();

        $this->assertStringContainsString('Extra mannequin-removal requests 1', $report);
        $this->assertStringContainsString('Requests spent 3', $report);
    }

    /** Anything older than the window has already aged out of the throttle. */
    public function test_work_outside_the_window_is_not_counted(): void
    {
        $this->item(['status' => 'edited', 'updated_at' => now()->subHours(30)]);
        $this->item(['status' => 'edited', 'updated_at' => now()->subHours(2)]);

        $this->assertStringContainsString('Requests spent 1', $this->report());
    }

    /** The refill schedule says when capacity comes back, not just that it will. */
    public function test_it_reports_when_each_hour_of_work_ages_out(): void
    {
        $this->item(['status' => 'edited', 'updated_at' => now()->subHours(6)]);

        $freesAt = now()->subHours(6)->startOfHour()->addHours(24)->format('D H:i');

        $this->assertStringContainsString($freesAt, $this->report());
    }

    /**
     * A live key is billed monthly, not daily, so it is reported against the
     * plan allowance and the date that allowance resets.
     */
    public function test_a_live_key_reports_the_monthly_allowance(): void
    {
        config([
            'services.photoroom.api_key'       => 'live_test_key',
            'services.photoroom.monthly_quota' => 1000,
        ]);

        $this->item(['status' => 'edited']);

        $report = $this->report();

        $this->assertStringContainsString('Monthly allowance 999 of 1000', $report);
        $this->assertStringContainsString('Resets', $report);

        // The hour-by-hour refill belongs to the sandbox's rolling window; a
        // monthly allowance does not trickle back and saying so would mislead.
        $this->assertStringNotContainsString('Frees up at', $report);
    }
}
