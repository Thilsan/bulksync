<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bell has to stay readable, and the table has to stay bounded.
 *
 * A sheet sync creates a couple of hundred requests and a notification apiece,
 * so an account copied on everything passes a thousand rows in a day. At that
 * point "99+" tells nobody anything, and nothing was ever deleting them inside
 * six months.
 */
class PruneNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Ahamed', 'email' => 'ahamed@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true,
        ]);
    }

    private function notification(User $user, int $hoursAgo, bool $read = false): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => 'App\\Notifications\\ProductRequestStatusChanged',
            'notifiable_type' => User::class,
            'notifiable_id'   => $user->id,
            'data'            => json_encode(['request_id' => 1]),
            'read_at'         => $read ? now()->subHours($hoursAgo) : null,
            'created_at'      => now()->subHours($hoursAgo),
            'updated_at'      => now()->subHours($hoursAgo),
        ]);

        return $id;
    }

    public function test_anything_older_than_two_days_goes(): void
    {
        $user = $this->user();

        $old    = $this->notification($user, hoursAgo: 49);
        $recent = $this->notification($user, hoursAgo: 47);

        $this->artisan('notifications:prune', ['--commit' => true])->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $old]);
        $this->assertDatabaseHas('notifications', ['id' => $recent]);
    }

    /** Unread is not a reason to keep it — later ones have overtaken it. */
    public function test_an_old_unread_notification_goes_too(): void
    {
        $user = $this->user();

        $unread = $this->notification($user, hoursAgo: 72, read: false);

        $this->artisan('notifications:prune', ['--commit' => true])->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $unread]);
    }

    public function test_a_dry_run_only_reports(): void
    {
        $user = $this->user();
        $old  = $this->notification($user, hoursAgo: 100);

        $this->artisan('notifications:prune')
            ->expectsOutputToContain('would go')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['id' => $old]);
    }

    /** The window is adjustable, for a one-off clear-out. */
    public function test_the_window_can_be_narrowed(): void
    {
        $user   = $this->user();
        $recent = $this->notification($user, hoursAgo: 5);

        $this->artisan('notifications:prune', ['--hours' => 1, '--commit' => true])->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $recent]);
    }

    public function test_it_says_so_when_there_is_nothing_to_do(): void
    {
        $this->notification($this->user(), hoursAgo: 1);

        $this->artisan('notifications:prune', ['--commit' => true])
            ->expectsOutputToContain('Nothing older than')
            ->assertSuccessful();
    }
}
