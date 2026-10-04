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

    /**
     * The two figures this whole report is measured against, pinned to what
     * Photoroom's own dashboard says.
     *
     * Nothing in this app can tell when either one is wrong — the usage bar
     * is only ever compared against itself — and both were, for months.
     * Checked side by side against Photoroom: the plan carries 2,000 images
     * and resets on the 14th, where this said 3,000 and the 18th. The wrong
     * reset day was the worse of the two: usage is counted from this app's
     * own edits since the last reset, so being four days late meant four
     * days of edits left out of the count, and the app reported 694 against
     * Photoroom's 1,069.
     *
     * A config default, not a measurement of anything this app can observe —
     * so if the plan changes, this test is the thing that has to change with
     * it, deliberately.
     */
    public function test_the_plan_figures_match_photorooms_own_dashboard(): void
    {
        $this->assertSame(2000, (int) config('services.photoroom.monthly_quota'),
            'the monthly allowance no longer matches the plan Photoroom bills');

        $this->assertSame(14, (int) config('services.photoroom.quota_resets_on'),
            'the reset day no longer matches Photoroom, so usage is counted from the wrong date');
    }

    /** Erasing a mannequin is a second request, spent before the edit itself. */
    public function test_mannequin_removal_counts_twice(): void
    {
        $this->item('edited', null, 'mannequin_removed');

        $this->assertSame(2, $this->report()['spent']);
    }

    /**
     * Neither mannequin-removal mode is produced any more — the erase pass
     * went when the classifier that routed to it did — but rows carrying
     * them predate that, and a report of what was spent has to stay right
     * about the months it is reporting on.
     */
    public function test_an_unverified_mannequin_removal_still_counts_twice(): void
    {
        $this->item('edited', null, 'mannequin_removed_unverified');

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

    /*
     * The history page carries the running totals, summed over every session
     * rather than the twenty on the page — a total that changed when you
     * turned the page would not be one.
     *
     * It used to carry an allowance bar too. That is gone: see
     * test_the_allowance_is_not_shown_on_screen below.
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

        foreach (['Sessions', 'Found', 'Edited', 'On Shopify', 'Failed'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // 10 + 5 found, 8 + 5 edited, 6 + 5 pushed, 2 + 0 failed.
        foreach (['15', '13', '11'] as $sum) {
            $this->assertStringContainsString('>' . $sum . '</p>', str_replace(["\n", ' '], ['', ''], $html),
                "the {$sum} total is missing");
        }
    }

    /*
     * The allowance is not shown on screen, and that is deliberate.
     *
     * It was a bar across the top of both Photo Editor screens: spent, left,
     * total, with a progress line. Every figure in it is reconstructed from
     * this app's own rows, so it can only ever undercount — an edit made in
     * Photoroom's web app, or by another holder of the key, is invisible to
     * it, and the total was a number typed into an env var by hand because
     * Photoroom's API does not report the plan.
     *
     * It read "75 left" on the morning Photoroom started answering 402, You
     * have exhausted the number of images in your plan. A caveat under it said
     * to treat the figure as a minimum; nobody reads a caveat under a number
     * that large and green.
     *
     * A number that is confidently wrong at the one moment it matters is worse
     * than no number, because a run gets planned against it. The report still
     * exists for `php artisan photoroom:usage`, where asking for an estimate
     * is a deliberate act and the output says what it is built from.
     */
    public function test_the_allowance_is_not_shown_on_screen(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        config(['services.photoroom.api_key' => 'live_sk_test']);

        $this->item('edited');

        foreach (['photo-editor.index', 'photo-editor.history'] as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertDontSee('monthly allowance')
                ->assertDontSee('sandbox allowance');
        }
    }

    /** And the figures it was built from are still available on demand. */
    public function test_the_usage_command_still_reports_it(): void
    {
        config(['services.photoroom.api_key' => 'live_sk_test']);

        $this->item('edited');

        $this->artisan('photoroom:usage')
            ->assertExitCode(0);
    }
}
