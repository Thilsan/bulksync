<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\User;
use App\Notifications\ProductRequestStatusChanged;
use App\Services\SkuMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductRequestTest extends TestCase
{
    use RefreshDatabase;

    private function brandManager(): User
    {
        return User::create([
            'name'                 => 'Brand Manager',
            'email'                => 'brand@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => 'brand_manager',
        ]);
    }

    public function test_a_request_can_be_submitted_with_unmapped_skus(): void
    {
        $user = $this->brandManager();

        $response = $this->actingAs($user)->post(route('product-requests.store'), [
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'department'                => 'Travel',
            'collection'                => 'Summer 2026',
            'skus'                      => "123456\n123457\n123458",
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'priority'                  => 'high',
        ]);

        $request = ProductRequest::first();

        $this->assertNotNull($request);
        $response->assertRedirect(route('product-requests.show', $request));

        $this->assertMatchesRegularExpression('/^PCR-\d{4}-\d{5}$/', $request->reference);
        $this->assertSame(3, $request->skus()->count());

        // Nothing is mapped yet, so the request parks itself with Supply Chain
        // instead of rejecting the submission.
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);
        $this->assertSame(3, $request->pending_skus);
    }

    public function test_submission_requires_at_least_one_sku(): void
    {
        $user = $this->brandManager();

        $this->actingAs($user)->post(route('product-requests.store'), [
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => '',
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'priority'                  => 'high',
        ])->assertSessionHasErrors('skus');

        $this->assertSame(0, ProductRequest::count());
    }

    public function test_supply_chain_mapping_releases_the_request_automatically(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->makeRequest($user, ['A-1', 'A-2']);

        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);

        $this->actingAs($user)->post(route('product-requests.skus.mapping', $request), [
            'sku_ids'        => $request->skus()->pluck('id')->all(),
            'mapping_status' => ProductRequest::MAP_MAPPED,
            'mapping_note'   => 'Mapped in Cegid by Supply Chain',
            'scope'          => 'selected',
        ])->assertRedirect();

        $request->refresh();

        // No re-submission needed — the request advances on its own.
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);
        $this->assertSame(2, $request->mapped_skus);
        $this->assertSame(0, $request->pending_skus);

        // The entry is attributed, so the audit trail shows who released it.
        $row = $request->skus()->first();
        $this->assertTrue($row->isManuallySet());
        $this->assertSame($user->id, $row->mapping_set_by);
        $this->assertSame('Mapped in Cegid by Supply Chain', $row->mapping_note);

        Notification::assertSentTo($user, ProductRequestStatusChanged::class);
    }

    public function test_the_automatic_check_never_overwrites_supply_chains_entry(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->makeRequest($user, ['G-1']);

        // Supply Chain says this one cannot be mapped yet.
        $this->actingAs($user)->post(route('product-requests.skus.mapping', $request), [
            'sku_ids'        => $request->skus()->pluck('id')->all(),
            'mapping_status' => ProductRequest::MAP_NOT_MAPPED,
            'scope'          => 'selected',
        ])->assertRedirect();

        $this->assertSame(ProductRequest::MAP_NOT_MAPPED, $request->skus()->first()->mapping_status);

        // A later re-validation must leave that decision alone.
        app(SkuMappingService::class)->validate($request->fresh());

        $this->assertSame(ProductRequest::MAP_NOT_MAPPED, $request->skus()->first()->mapping_status);
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->fresh()->status);
    }

    public function test_a_request_cannot_leave_waiting_for_mapping_while_skus_are_unmapped(): void
    {
        $user    = $this->brandManager();
        $request = $this->makeRequest($user, ['B-1']);

        $this->actingAs($user)->post(route('product-requests.transition', $request), [
            'to_status' => ProductRequest::READY_FOR_UPLOAD,
        ])->assertSessionHasErrors('to_status');

        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->fresh()->status);
    }

    public function test_every_status_change_is_written_to_the_audit_trail(): void
    {
        Notification::fake();

        $user = $this->brandManager();

        // Submit through the real endpoint so the trail is covered from creation.
        $this->actingAs($user)->post(route('product-requests.store'), [
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'C-1',
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'priority'                  => 'high',
        ]);

        $request = ProductRequest::first();

        $this->markMapped($request);

        $this->actingAs($user)->post(route('product-requests.transition', $request), [
            'to_status' => ProductRequest::WAITING_IMAGES,
            'remarks'   => 'Booking the studio',
        ])->assertRedirect();

        $trail = ProductRequestActivity::where('product_request_id', $request->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($trail->contains(fn ($a) => $a->action === 'created'));
        $this->assertTrue($trail->contains(fn ($a) => $a->to_status === ProductRequest::WAITING_MAPPING));
        $this->assertTrue($trail->contains(fn ($a) => $a->to_status === ProductRequest::SKU_VERIFIED));

        $last = $trail->last();
        $this->assertSame(ProductRequest::SKU_VERIFIED, $last->from_status);
        $this->assertSame(ProductRequest::WAITING_IMAGES, $last->to_status);
        $this->assertSame('Booking the studio', $last->remarks);
        $this->assertSame($user->id, $last->user_id);
    }

    public function test_users_without_the_permission_are_refused(): void
    {
        $outsider = User::create([
            'name'                 => 'No Access',
            'email'                => 'nope@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => false,
        ]);

        $this->actingAs($outsider)->get(route('product-requests.index'))->assertForbidden();
    }

    public function test_a_request_is_hidden_from_unrelated_users(): void
    {
        $owner   = $this->brandManager();
        $request = $this->makeRequest($owner, ['D-1']);

        $other = User::create([
            'name'                 => 'Other Team',
            'email'                => 'other@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => null,
        ]);

        $this->actingAs($other)->get(route('product-requests.show', $request))->assertForbidden();
    }

    public function test_cancelling_closes_the_request(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->makeRequest($user, ['E-1']);

        $this->actingAs($user)->post(route('product-requests.cancel', $request), [
            'cancel_reason' => 'Brand postponed the launch',
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(ProductRequest::CANCELLED, $request->status);
        $this->assertNotNull($request->cancelled_at);
        $this->assertTrue($request->isClosed());
        $this->assertSame([], $request->allowedTransitions());
    }

    public function test_skus_export_streams_the_requested_subset(): void
    {
        $user    = $this->brandManager();
        $request = $this->makeRequest($user, ['F-1', 'F-2']);

        $response = $this->actingAs($user)
            ->get(route('product-requests.skus.download', [$request, 'filter' => ProductRequest::MAP_PENDING]));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('F-1', $csv);
        $this->assertStringContainsString('F-2', $csv);
        $this->assertStringContainsString('Pending Mapping', $csv);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeRequest(User $user, array $skus): ProductRequest
    {
        $request = ProductRequest::create([
            'reference'           => ProductRequest::nextReference(),
            'user_id'             => $user->id,
            'request_type'        => 'new_brand',
            'brand'               => 'Samsonite',
            'category'            => 'Luggage',
            'status'              => ProductRequest::SUBMITTED,
            'priority'            => 'medium',
            'store_launch_date'   => now()->addDays(20),
            'online_launch_date'  => now()->addDays(18),
            'photoshoot_required' => true,
            'validation_status'   => 'pending',
        ]);

        $mapping = app(SkuMappingService::class);
        $mapping->syncSkus($request, $skus);
        $mapping->rollUp($request, 'completed');

        app(\App\Services\ProductRequestWorkflow::class)->reconcileMapping($request->refresh(), $user);

        return $request->refresh();
    }

    private function markMapped(ProductRequest $request): void
    {
        $request->skus()->update([
            'mapping_status' => ProductRequest::MAP_MAPPED,
            'mapping_set_by' => $request->user_id,
            'mapping_set_at' => now(),
        ]);

        $mapping = app(SkuMappingService::class);
        $mapping->rollUp($request);

        app(\App\Services\ProductRequestWorkflow::class)->reconcileMapping($request->refresh());

        $request->refresh();
    }
}
