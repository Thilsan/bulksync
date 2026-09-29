<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\SkuMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * shopify_verified_at is the difference between "we looked and they are not
 * there" and "we never got an answer" — the sheet sync reopens a published
 * request on the strength of it, so a stale one is worse than none.
 */
class SkuMappingVerificationStampTest extends TestCase
{
    use RefreshDatabase;

    private function request(): ProductRequest
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'password', 'is_active' => true, 'perm_product_request' => true,
        ]);

        // No Shopify credentials, so the lookup can never answer.
        $store = Store::create([
            'name' => 'Mosafer Website', 'shopify_domain' => 'unreachable.myshopify.com',
            'is_active' => true, 'requires_sku_mapping' => false,
        ]);

        $request = ProductRequest::create([
            'reference' => ProductRequest::nextReference(),
            'user_id' => $user->id, 'store_id' => $store->id,
            'request_type' => 'new_brand', 'brand' => 'AMERICAN TOURISTER',
            'category' => 'Luggage', 'status' => ProductRequest::SKU_VERIFIED,
            'priority' => 'medium', 'validation_status' => 'pending',
        ]);

        app(SkuMappingService::class)->syncSkus($request, ['SKU-1', 'SKU-2']);

        return $request->refresh();
    }

    public function test_a_run_that_never_reached_shopify_clears_the_stamp(): void
    {
        $request = $this->request();

        // A previous run that did reach Shopify.
        $request->update(['shopify_verified_at' => now()->subHour()]);

        app(SkuMappingService::class)->validate($request);

        $this->assertNull($request->refresh()->shopify_verified_at);
        $this->assertFalse($request->nothingLiveInShopify());
    }
}
