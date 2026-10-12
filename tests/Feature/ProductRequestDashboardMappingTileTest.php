<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Waiting for Mapping tile counts requests, and one request can hold
 * hundreds of SKUs — so the tile also says how many SKUs are still to map,
 * or "61" reads as wrong next to a request showing 322 unmapped.
 */
class ProductRequestDashboardMappingTileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mapping_tile_shows_the_unmapped_skus_inside_its_requests(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
        ]);
        $store = Store::create(['name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com', 'is_active' => true]);

        foreach ([[322, 0], [40, 15]] as [$total, $mapped]) {
            ProductRequest::create([
                'reference' => ProductRequest::nextReference(), 'user_id' => $admin->id, 'store_id' => $store->id,
                'request_type' => 'new_brand', 'brand' => 'BRAND', 'category' => 'Leather Goods',
                'status' => ProductRequest::WAITING_MAPPING, 'priority' => 'medium',
                'total_skus' => $total, 'mapped_skus' => $mapped,
            ]);
        }

        $this->actingAs($admin)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee('347 SKUs to map');
    }
}
