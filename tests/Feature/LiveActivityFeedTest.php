<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The top bar's live ticker: admins see what everyone else is doing, nobody
 * else sees it at all.
 */
class LiveActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->create(['is_active' => true, 'is_super_admin' => true, 'name' => 'Admin']);
        $this->member = User::factory()->create(['is_active' => true, 'name' => 'Sara']);
    }

    private function log(User $user, string $action, ?string $description, $at = null): void
    {
        ActivityLog::create([
            'user_id'     => $user->id,
            'action'      => $action,
            'description' => $description,
            'created_at'  => $at ?? now(),
        ]);
    }

    public function test_feed_reports_what_other_users_are_doing(): void
    {
        $this->log($this->member, ActivityLog::ACTION_LOGIN, 'Logged in', now()->subMinutes(5));
        $this->log($this->member, ActivityLog::ACTION_PAGE_VIEW, 'SKU Checker', now()->subMinutes(2));

        $response = $this->actingAs($this->admin)->getJson(route('super-admin.live-feed'))->assertOk();

        $this->assertSame(['is on SKU Checker', 'signed in'], array_column($response->json('items'), 'text'));
        $this->assertSame('Sara', $response->json('items.0.user'));
        $this->assertSame(1, $response->json('online'));
    }

    public function test_feed_leaves_out_the_viewer_old_entries_failed_logins_and_repeats(): void
    {
        $this->log($this->admin, ActivityLog::ACTION_PAGE_VIEW, 'Admin Panel');
        $this->log($this->member, ActivityLog::ACTION_PAGE_VIEW, 'Stores', now()->subMinutes(31));
        $this->log($this->member, ActivityLog::ACTION_LOGIN_FAILED, 'Failed login attempt for "x@example.com"');
        $this->log($this->member, ActivityLog::ACTION_PAGE_VIEW, 'Dashboard', now()->subMinutes(3));
        $this->log($this->member, ActivityLog::ACTION_PAGE_VIEW, 'Dashboard', now()->subMinute());

        $items = $this->actingAs($this->admin)->getJson(route('super-admin.live-feed'))->json('items');

        $this->assertSame(['is on Dashboard'], array_column($items, 'text'));
    }

    public function test_feed_includes_product_request_moves_with_a_link(): void
    {
        $request = ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => $this->member->id,
            'request_type' => 'new_brand',
            'brand'        => 'Acme',
            'category'     => 'Lingerie',
            'status'       => 'pending',
            'priority'     => 'medium',
        ]);

        ProductRequestActivity::create([
            'product_request_id' => $request->id,
            'user_id'            => $this->member->id,
            'action'             => 'updated',
            'description'        => 'Priority changed from Low to High',
            'created_at'         => now(),
        ]);

        $item = $this->actingAs($this->admin)->getJson(route('super-admin.live-feed'))->json('items.0');

        $this->assertSame("priority changed from Low to High · {$request->reference}", $item['text']);
        $this->assertSame(route('product-requests.show', $request->id), $item['url']);
    }

    public function test_nobody_counts_as_online_once_their_last_action_is_half_an_hour_old(): void
    {
        $this->log($this->admin, ActivityLog::ACTION_PAGE_VIEW, 'Admin Panel');
        $this->log($this->member, ActivityLog::ACTION_PAGE_VIEW, 'Stores', now()->subMinutes(31));

        $response = $this->actingAs($this->admin)->getJson(route('super-admin.live-feed'))->assertOk();

        $this->assertSame(0, $response->json('online'));
        $this->assertSame([], $response->json('items'));
    }

    public function test_ticker_stays_hidden_while_switched_off(): void
    {
        config(['app.live_ticker' => false]);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('super-admin.live-feed'));
    }

    public function test_feed_and_ticker_are_admin_only(): void
    {
        config(['app.live_ticker' => true]);

        $this->actingAs($this->member)->getJson(route('super-admin.live-feed'))->assertForbidden();

        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('super-admin.live-feed'));

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('super-admin.live-feed'));
    }
}
