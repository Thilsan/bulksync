<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Support\PhotoroomAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three numbers the Photo Editor screen leads with: spent, left, total.
 *
 * They are rebuilt from item rows because nothing records a Photoroom request
 * as it happens, which makes the subtle part not the arithmetic but which
 * failures were charged for.
 */
class PhotoroomAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $status, ?string $error = null, ?string $mode = null): PhotoEditItem
    {
        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
        ]);

        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => $status,
            'error_message'         => $error,
            'apparel_mode_applied'  => $mode,
        ]);
    }

    /**
     * A live key, set explicitly. The developer .env holds a sandbox one, and
     * inherited into a test it silently swaps a 1,000-a-month allowance for a
     * 100-a-day one — so the key is stated here rather than assumed.
     */
    private function report(int $quota = 1000): array
    {
        config([
            'services.photoroom.api_key'       => 'live_sk_test',
            'services.photoroom.monthly_quota' => $quota,
        ]);

        return app(PhotoroomAllowance::class)->report();
    }

    public function test_the_three_numbers_add_up(): void
    {
        $this->item('edited');
        $this->item('edited');
        $this->item('pushed');

        $r = $this->report();

        $this->assertSame(3, $r['spent']);
        $this->assertSame(1000, $r['quota']);
        $this->assertSame(997, $r['left']);
        $this->assertSame($r['quota'], $r['spent'] + $r['left']);
    }

    /** A throttled request never reached Photoroom's meter, so it is free. */
    public function test_throttled_failures_are_not_charged(): void
    {
        $this->item('failed', 'Photoroom returned 429: throttled');
        $this->item('failed', 'Photoroom quota is exhausted');

        $this->assertSame(0, $this->report()['spent']);
    }

    /** Any other failure was uploaded before it was refused, so it was billed. */
    public function test_other_failures_are_charged(): void
    {
        $this->item('failed', 'Photoroom returned 400: Images deeper than 8-bit are not supported');

        $r = $this->report();

        $this->assertSame(1, $r['spent']);
        $this->assertSame(1, $r['charged_failures']);
    }

    /** Erasing a mannequin is a second request, spent before the edit itself. */
    public function test_mannequin_removal_counts_twice(): void
    {
        $this->item('edited', null, 'mannequin_removed');

        $this->assertSame(2, $this->report()['spent']);
    }

    /** Nothing left must not read as a negative allowance. */
    public function test_an_overspent_allowance_floors_at_zero(): void
    {
        $this->item('edited');
        $this->item('edited');
        $this->item('edited');

        $r = $this->report(quota: 2);

        $this->assertSame(3, $r['spent']);
        $this->assertSame(0, $r['left']);
        $this->assertSame(100, $r['percent_used'], 'the bar must not overflow its track');
    }

    /**
     * A sandbox key is a different allowance entirely — 100 a day on a rolling
     * window, not 1,000 a month — so the screen must not quote the monthly one
     * at somebody whose edits come back watermarked.
     */
    public function test_a_sandbox_key_reports_its_own_daily_allowance(): void
    {
        config([
            'services.photoroom.api_key'       => 'sandbox_sk_test',
            'services.photoroom.monthly_quota' => 1000,
        ]);

        $this->item('edited');

        $r = app(PhotoroomAllowance::class)->report();

        $this->assertTrue($r['is_sandbox']);
        $this->assertSame(PhotoroomAllowance::SANDBOX_DAILY_CAP, $r['quota']);
        $this->assertSame(24, $r['window_hours']);
        $this->assertNull($r['resets_on'], 'a rolling window has no reset date');
    }

    /**
     * The history page is the Photo Editor's dashboard, so it carries both the
     * allowance and the running totals — and the totals are summed over every
     * session rather than the twenty on the page, since a total that changed
     * when you turned the page would not be one.
     */
    public function test_the_history_page_totals_every_session_not_just_the_page(): void
    {
        config(['services.photoroom.api_key' => 'live_sk_test']);

        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        foreach ([[10, 8, 6, 2], [5, 5, 5, 0]] as [$found, $edited, $pushed, $failed]) {
            PhotoEditSession::create([
                'user_id'       => $user->id,
                'name'          => 'Run',
                'onedrive_link' => 'https://example.com',
                'edits'         => ['remove_background' => true],
                'total_files'   => $found,
                'edited_files'  => $edited,
                'pushed_files'  => $pushed,
                'failed_files'  => $failed,
            ]);
        }

        $html = $this->actingAs($user)->get(route('photo-editor.history'))->assertOk()->getContent();

        foreach (['Sessions', 'Found', 'Edited', 'On Shopify', 'Failed', 'Left', 'Total'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // 10 + 5 found, 8 + 5 edited, 6 + 5 pushed, 2 + 0 failed.
        foreach (['15', '13', '11'] as $sum) {
            $this->assertStringContainsString('>' . $sum . '</p>', str_replace(["\n", ' '], ['', ''], $html),
                "the {$sum} total is missing");
        }
    }

    /** The screen leads with these, so the page must render them. */
    public function test_the_photo_editor_screen_shows_the_allowance(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        config(['services.photoroom.api_key' => 'live_sk_test']);

        $this->item('edited');

        $this->actingAs($user)
            ->get(route('photo-editor.index'))
            ->assertOk()
            ->assertSee('Edited')
            ->assertSee('Left')
            ->assertSee('Total');
    }
}
