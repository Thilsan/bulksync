<?php

namespace Tests\Feature;

use App\Jobs\RunSeoAuditJob;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The SEO audit reports, per product, what is missing or wrong about the
 * fields a search engine actually reads.
 *
 * The grading is the part worth holding still: the bounds are judgement calls
 * that will be argued about, and duplicates are the one check a product cannot
 * answer about itself — it takes the whole catalogue to know.
 */
class SeoAuditTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        // is_active matters: an inactive user is bounced to the login screen
        // and every assertion below then reads as a routing failure.
        return User::create([
            'name'            => 'SEO Auditor',
            'email'           => 'seo@example.test',
            'password'        => 'password',
            'is_active'       => true,
            'perm_seo_audit'  => true,
        ]);
    }

    private function storeFor(User $user): Store
    {
        return Store::create([
            'name'                 => 'Test Store',
            'shopify_domain'       => 'test.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'user_id'              => $user->id,
        ]);
    }

    /** A product as streamProductsForSeoAudit() shapes it, with everything right. */
    private function healthyProduct(array $overrides = []): array
    {
        return array_merge([
            'id'               => '1',
            'title'            => 'Relaxed Linen Shirt',
            'handle'           => 'relaxed-linen-shirt',
            'tags'             => ['shirts', 'linen'],
            'description'      => str_repeat('A well written product description. ', 10),
            'meta_title'       => 'Relaxed Linen Shirt with Camp Collar for Men',
            'meta_description' => 'A breathable relaxed-fit linen shirt with a camp collar and '
                                . 'mother-of-pearl buttons, available at Test Store in Qatar.',
            'images'           => [['alt' => 'Light blue relaxed linen shirt']],
            'sku'              => 'SHIRT-1',
        ], $overrides);
    }

    /** Runs the job against a fake catalogue, returning the finished session. */
    private function audit(User $user, Store $store, array $products): SeoAuditSession
    {
        $session = SeoAuditSession::create([
            'user_id'  => $user->id,
            'store_id' => $store->id,
            'status'   => 'pending',
        ]);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductCount')->andReturn(count($products));
        $shopify->shouldReceive('streamProductsForSeoAudit')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback($products));

        $job = new class($session->id, $shopify) extends RunSeoAuditJob {
            public function __construct(int $sessionId, private $fake)
            {
                parent::__construct($sessionId);
            }

            protected function shopifyFor(?Store $store): ShopifyService
            {
                return $this->fake;
            }
        };

        $job->handle();

        return $session->fresh();
    }

    public function test_a_fully_populated_product_raises_nothing(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [$this->healthyProduct()]);

        $this->assertSame('completed', $session->status);
        $this->assertSame(1, $session->clean_products);
        $this->assertSame(0, $session->products_with_issues);
        $this->assertSame(100, $session->average_score);

        $this->assertSame([], $session->items()->first()->issues);
    }

    public function test_empty_seo_fields_are_reported_as_missing_not_as_too_short(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [$this->healthyProduct([
            'meta_title'       => null,
            'meta_description' => '',
        ])]);

        $issues = $session->items()->first()->issues;

        $this->assertContains('missing_meta_title', $issues);
        $this->assertContains('missing_meta_description', $issues);

        // An absent field is one problem, not two — a blank title must not also
        // be graded as a title that is under thirty characters.
        $this->assertNotContains('meta_title_too_short', $issues);
        $this->assertNotContains('meta_description_too_short', $issues);
    }

    public function test_length_bounds_are_measured_in_characters_so_arabic_copy_is_not_flagged(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        // 45 Arabic characters: comfortably inside the 60-character limit, but
        // around 80 bytes — strlen() would call this one too long.
        $arabicTitle = str_repeat('ق', 45);

        $session = $this->audit($user, $store, [$this->healthyProduct([
            'meta_title' => $arabicTitle,
        ])]);

        $item = $session->items()->first();

        $this->assertSame(45, $item->meta_title_length);
        $this->assertNotContains('meta_title_too_long', $item->issues);
    }

    public function test_an_oversized_meta_title_costs_points_without_being_called_missing(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [$this->healthyProduct([
            'meta_title' => str_repeat('a', 75),
        ])]);

        $item = $session->items()->first();

        $this->assertContains('meta_title_too_long', $item->issues);
        $this->assertNotContains('missing_meta_title', $item->issues);
        $this->assertSame(100 - SeoAuditItem::ISSUES['meta_title_too_long']['weight'], $item->score);
    }

    public function test_images_without_alt_text_are_counted_and_a_product_with_none_is_worse(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct([
                'id'     => '1',
                'images' => [['alt' => 'Described'], ['alt' => ''], ['alt' => null]],
            ]),
            $this->healthyProduct(['id' => '2', 'images' => []]),
        ]);

        $partial = $session->items()->where('product_id', '1')->first();
        $this->assertSame(3, $partial->image_count);
        $this->assertSame(2, $partial->images_missing_alt);
        $this->assertContains('missing_alt_text', $partial->issues);

        // No images at all is a different, heavier problem — and not also an
        // alt-text gap, which would double-count the same absence.
        $none = $session->items()->where('product_id', '2')->first();
        $this->assertContains('no_images', $none->issues);
        $this->assertNotContains('missing_alt_text', $none->issues);
    }

    public function test_both_products_sharing_a_meta_title_are_flagged_not_just_the_second(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $shared = 'Relaxed Linen Shirt with Camp Collar for Men';

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1', 'meta_title' => $shared]),
            // Same title, different case and padding: still the same result page.
            $this->healthyProduct(['id' => '2', 'meta_title' => '  ' . strtoupper($shared) . ' ']),
            $this->healthyProduct(['id' => '3', 'meta_title' => 'A Different Title Of Its Own Here']),
        ]);

        foreach (['1', '2'] as $productId) {
            $this->assertContains(
                'duplicate_meta_title',
                $session->items()->where('product_id', $productId)->first()->issues,
                "Product {$productId} should be flagged as a duplicate",
            );
        }

        $this->assertNotContains(
            'duplicate_meta_title',
            $session->items()->where('product_id', '3')->first()->issues,
        );

        $this->assertSame(2, $session->issue_breakdown['duplicate_meta_title']);
    }

    public function test_products_missing_a_meta_title_are_not_duplicates_of_each_other(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1', 'meta_title' => null]),
            $this->healthyProduct(['id' => '2', 'meta_title' => null]),
        ]);

        foreach (['1', '2'] as $productId) {
            $issues = $session->items()->where('product_id', $productId)->first()->issues;

            $this->assertContains('missing_meta_title', $issues);
            // Two blanks are two absences, not a clash between them.
            $this->assertNotContains('duplicate_meta_title', $issues);
        }
    }

    public function test_a_rerun_replaces_the_previous_result_rather_than_stacking_on_it(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [$this->healthyProduct()]);
        $this->assertSame(1, $session->items()->count());

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductCount')->andReturn(1);
        $shopify->shouldReceive('streamProductsForSeoAudit')
            ->once()
            ->andReturnUsing(fn (callable $cb) => $cb([$this->healthyProduct()]));

        $job = new class($session->id, $shopify) extends RunSeoAuditJob {
            public function __construct(int $sessionId, private $fake)
            {
                parent::__construct($sessionId);
            }

            protected function shopifyFor(?Store $store): ShopifyService
            {
                return $this->fake;
            }
        };
        $job->handle();

        $this->assertSame(1, $session->fresh()->items()->count());
    }

    public function test_the_table_can_be_filtered_down_to_one_issue(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1']),
            // A distinct meta description, or the two fixtures would flag each
            // other as duplicates and both land in the "with issues" filter.
            $this->healthyProduct([
                'id'               => '2',
                'sku'              => 'SHIRT-2',
                'meta_title'       => null,
                'meta_description' => 'A lightweight cotton overshirt with patch pockets and a '
                                    . 'straight hem, available at Test Store in Qatar.',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('seo-audit.items', $session) . '?filter=missing_meta_title');

        $response->assertOk();
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('SHIRT-2', $response->json('items.0.sku'));
    }

    public function test_the_csv_carries_the_rows_the_filter_is_showing(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1']),
            // A distinct meta description, or the two fixtures would flag each
            // other as duplicates and both land in the "with issues" filter.
            $this->healthyProduct([
                'id'               => '2',
                'sku'              => 'SHIRT-2',
                'meta_title'       => null,
                'meta_description' => 'A lightweight cotton overshirt with patch pockets and a '
                                    . 'straight hem, available at Test Store in Qatar.',
            ]),
        ]);

        $csv = $this->actingAs($user)
            ->get(route('seo-audit.download', $session) . '?filter=issues')
            ->streamedContent();

        $this->assertStringContainsString('SHIRT-2', $csv);
        $this->assertStringContainsString('No meta title', $csv);
        $this->assertStringNotContainsString('SHIRT-1', $csv);
    }

    public function test_another_users_audit_is_not_readable(): void
    {
        $owner = $this->operator();
        $store = $this->storeFor($owner);
        $session = $this->audit($owner, $store, [$this->healthyProduct()]);

        $intruder = User::create([
            'name'           => 'Someone Else',
            'email'          => 'other@example.test',
            'password'       => 'password',
            'is_active'      => true,
            'perm_seo_audit' => true,
        ]);

        $this->actingAs($intruder)
            ->get(route('seo-audit.show', $session))
            ->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
