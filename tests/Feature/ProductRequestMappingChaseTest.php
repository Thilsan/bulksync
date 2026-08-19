<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestMappingNeeded;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * On a Cegid website the brand manager does the mapping themselves, so an
 * unmapped balance is a request to them — with the list — not a queue somebody
 * else works through.
 */
class ProductRequestMappingChaseTest extends TestCase
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
            'pcr_brand_categories' => ['Lingerie'],
        ]);
    }

    private function store(bool $cegid = true): Store
    {
        return Store::create([
            'name'                 => 'Bluesalon Website',
            'shopify_domain'       => 'qatarbluesalon.myshopify.com',
            'is_active'            => true,
            'requires_sku_mapping' => $cegid,
        ]);
    }

    /** @param array<int, array{sku: string, mapped: bool}> $skus */
    private function request(Store $store, User $user, array $skus, string $status = ProductRequest::WAITING_MAPPING): ProductRequest
    {
        $mapped = count(array_filter($skus, fn ($s) => $s['mapped']));

        $request = ProductRequest::create([
            'reference'       => ProductRequest::nextReference(),
            'user_id'         => $user->id,
            'store_id'        => $store->id,
            'request_type'    => 'new_brand',
            'brand'           => 'HANRO',
            'category'        => 'Lingerie',
            'status'          => $status,
            'priority'        => 'medium',
            'total_skus'      => count($skus),
            'mapped_skus'     => $mapped,
            'pending_skus'    => count($skus) - $mapped,
            'not_mapped_skus' => 0,
        ]);

        foreach ($skus as $row) {
            ProductRequestSku::create([
                'product_request_id' => $request->id,
                'sku'                => $row['sku'],
                'mapping_status'     => $row['mapped'] ? ProductRequest::MAP_MAPPED : ProductRequest::MAP_PENDING,
                'in_shopify'         => $row['mapped'],
            ]);
        }

        return $request;
    }

    public function test_the_brand_manager_is_asked_to_map_the_balance(): void
    {
        Notification::fake();

        $manager = $this->brandManager();
        $request = $this->request($this->store(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ]);

        $told = app(ProductRequestWorkflow::class)->askForMapping($request);

        $this->assertSame($manager->id, $told?->id);

        Notification::assertSentTo($manager, ProductRequestMappingNeeded::class, function ($notification) {
            // The counts the brand manager needs: what is live, what is not.
            return $notification->mapped === 1
                && $notification->total === 2
                && count($notification->pending) === 1
                && $notification->pending[0]['sku'] === 'A-2';
        });
    }

    /** Everywhere else there is no mapping step, so there is nothing to ask for. */
    public function test_a_non_cegid_website_is_never_asked(): void
    {
        Notification::fake();

        $manager = $this->brandManager();
        $request = $this->request($this->store(cegid: false), $manager, [
            ['sku' => 'A-1', 'mapped' => false],
        ]);

        $this->assertNull(app(ProductRequestWorkflow::class)->askForMapping($request));

        Notification::assertNothingSent();
    }

    public function test_a_fully_mapped_request_is_never_chased(): void
    {
        Notification::fake();

        $manager = $this->brandManager();
        $request = $this->request($this->store(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
        ]);

        $this->assertNull(app(ProductRequestWorkflow::class)->askForMapping($request));

        Notification::assertNothingSent();
    }

    /** Asked once when the request parks — not again on every hourly check. */
    public function test_parking_in_waiting_for_mapping_asks_once(): void
    {
        Notification::fake();

        $manager = $this->brandManager();
        $request = $this->request($this->store(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ], status: ProductRequest::SUBMITTED);

        $workflow = app(ProductRequestWorkflow::class);

        $workflow->reconcileMapping($request);
        $workflow->reconcileMapping($request->refresh());   // the next hourly check

        Notification::assertSentToTimes($manager, ProductRequestMappingNeeded::class, 1);
    }

    /** A bulk correction must not mail anybody, the same as every other path. */
    public function test_a_silent_reconcile_asks_nobody(): void
    {
        Notification::fake();

        $manager = $this->brandManager();
        $request = $this->request($this->store(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ], status: ProductRequest::SUBMITTED);

        app(ProductRequestWorkflow::class)->reconcileMapping($request, null, notify: false);

        Notification::assertNothingSent();
    }

    public function test_the_chase_button_asks_again(): void
    {
        Notification::fake();

        $manager = $this->brandManager();
        $manager->stores()->attach($this->store()->id);
        $request = $this->request(Store::sole(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ]);

        $this->actingAs($manager)
            ->post(route('product-requests.chase-mapping', $request))
            ->assertRedirect();

        Notification::assertSentTo($manager, ProductRequestMappingNeeded::class);
    }

    // ── The AI content decision, and what publishing says about it ───────────

    public function test_carrying_on_can_start_ai_content_for_the_mapped_skus(): void
    {
        Notification::fake();
        Queue::fake();

        $manager = $this->brandManager();
        $manager->stores()->attach($this->store()->id);
        $request = $this->request(Store::sole(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ]);

        $this->actingAs($manager)
            ->post(route('product-requests.continue-mapped', $request), ['ai_content' => 'generate'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('generate', $request->ai_content_decision);
        $this->assertNotNull($request->ai_content_session_id);

        Queue::assertPushed(GenerateAiContentJob::class);
    }

    public function test_skipping_ai_content_is_recorded_rather_than_forgotten(): void
    {
        Notification::fake();
        Queue::fake();

        $manager = $this->brandManager();
        $manager->stores()->attach($this->store()->id);
        $request = $this->request(Store::sole(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ]);

        $this->actingAs($manager)
            ->post(route('product-requests.continue-mapped', $request), ['ai_content' => 'skip'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('skip', $request->ai_content_decision);
        $this->assertNull($request->ai_content_session_id);

        Queue::assertNotPushed(GenerateAiContentJob::class);
    }

    /** Publishing is the last chance to say what did not make it. */
    public function test_publishing_names_what_was_skipped(): void
    {
        Notification::fake();
        Queue::fake();

        $manager = $this->brandManager();
        $manager->stores()->attach($this->store()->id);
        $request = $this->request(Store::sole(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
            ['sku' => 'A-2', 'mapped' => false],
        ], status: ProductRequest::QA_REVIEW);

        $request->update(['ai_content_decision' => 'skip']);

        $gaps = $request->publishGaps();

        $this->assertStringContainsString('never mapped in Cegid', implode(' ', $gaps));
        $this->assertStringContainsString('AI content skipped', implode(' ', $gaps));

        $this->actingAs($manager)
            ->post(route('product-requests.transition', $request), ['to_status' => ProductRequest::PUBLISHED])
            ->assertRedirect()
            ->assertSessionHas('warning');

        // And it is on the record, not only in a flash message somebody dismissed.
        $this->assertTrue(
            $request->activities()->where('remarks', 'like', '%never mapped in Cegid%')->exists(),
            'The publish activity should say what was skipped.',
        );
    }

    public function test_a_complete_request_publishes_with_nothing_to_report(): void
    {
        Notification::fake();
        Queue::fake();

        $manager = $this->brandManager();
        $manager->stores()->attach($this->store()->id);
        $request = $this->request(Store::sole(), $manager, [
            ['sku' => 'A-1', 'mapped' => true],
        ], status: ProductRequest::QA_REVIEW);

        $request->update(['ai_content_decision' => 'generate', 'ai_content_session_id' => null, 'use_ai_content' => false]);

        $this->assertSame([], $request->publishGaps());

        $this->actingAs($manager)
            ->post(route('product-requests.transition', $request), ['to_status' => ProductRequest::PUBLISHED])
            ->assertRedirect()
            ->assertSessionHas('success');
    }
}
