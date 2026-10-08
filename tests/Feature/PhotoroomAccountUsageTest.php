<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The allowance shown on screen is Photoroom's own number.
 *
 * The first version of this card added up the app's own rows. It could only
 * undercount — an edit made in Photoroom's web app, or by anyone else holding
 * the key, was invisible to it, and the plan size was an env var typed in by
 * hand. It read "75 left" on the morning Photoroom started answering 402, and
 * a run was planned against it, so the card was taken off the screen.
 *
 * GET /v2/account is the figure from the account itself, and the rule that
 * replaces the old caveat is simpler than a caveat: when it cannot be had,
 * nothing is shown.
 */
class PhotoroomAccountUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.photoroom.api_key' => 'live_sk_test']);
    }

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    public function test_the_figures_come_from_photoroom(): void
    {
        Http::fake(['*/v2/account' => Http::response([
            'images' => ['available' => 4998, 'subscription' => 5000],
            'plan'   => 'plus',
        ])]);

        $usage = app(PhotoroomService::class)->accountUsage();

        $this->assertSame(4998, $usage['available']);
        $this->assertSame(5000, $usage['subscription']);
        $this->assertSame(2, $usage['used'], 'used is the subscription less what is left, not our own tally');
        $this->assertSame('plus', $usage['plan']);
    }

    /** Asked once and held, since this renders on every page load. */
    public function test_the_lookup_is_cached(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['images' => ['available' => 10, 'subscription' => 20]]);
        });

        app(PhotoroomService::class)->accountUsage();
        app(PhotoroomService::class)->accountUsage();

        $this->assertSame(1, $calls);
    }

    /**
     * A response that changed shape reads as nothing, not as a spent plan.
     *
     * Zero left is the one wrong answer that would stop a day's work, so it is
     * never inferred from a missing field.
     */
    public function test_an_unreadable_response_gives_nothing(): void
    {
        Http::fake(['*/v2/account' => Http::response(['plan' => 'plus'])]);

        $this->assertNull(app(PhotoroomService::class)->accountUsage());
    }

    public function test_a_failed_lookup_gives_nothing(): void
    {
        Http::fake(['*/v2/account' => Http::response('nope', 500)]);

        $this->assertNull(app(PhotoroomService::class)->accountUsage());
    }

    public function test_the_screen_shows_the_figures_when_they_are_available(): void
    {
        Http::fake(['*/v2/account' => Http::response([
            'images' => ['available' => 4998, 'subscription' => 5000],
            'plan'   => 'plus',
        ])]);

        $this->actingAs($this->editor())
            ->get(route('photo-editor.index'))
            ->assertOk()
            ->assertSee('Photoroom plan')
            ->assertSee('4,998')
            ->assertSee('5,000');
    }

    /** And shows no card at all when they cannot be had. */
    public function test_the_screen_shows_nothing_when_photoroom_cannot_be_reached(): void
    {
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $this->actingAs($this->editor())
            ->get(route('photo-editor.index'))
            ->assertOk()
            ->assertDontSee('Photoroom plan');
    }
}
