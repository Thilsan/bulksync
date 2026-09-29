<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\RunSeoAuditJob;
use App\Http\Controllers\SeoAuditController;
use App\Models\AiContentSession;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    /** A collection as streamCollectionsForSeoAudit() shapes it. */
    private function healthyCollection(array $overrides = []): array
    {
        return array_merge([
            'id'               => '100',
            'title'            => 'Cabin Luggage for Short Trips',
            'handle'           => 'cabin-luggage',
            'description'      => str_repeat('A considered collection description. ', 10),
            'meta_title'       => 'Cabin Luggage and Carry-On Suitcases in Qatar',
            'meta_description' => 'Hard and soft cabin suitcases sized for airline lockers, '
                                . 'with four-wheel spinners and TSA locks, at Test Store in Qatar.',
        ], $overrides);
    }

    /** Runs the job against a fake catalogue, returning the finished session. */
    private function audit(User $user, Store $store, array $products, array $collections = []): SeoAuditSession
    {
        $session = SeoAuditSession::create([
            'user_id'  => $user->id,
            'store_id' => $store->id,
            'status'   => 'pending',
        ]);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductCount')->andReturn(count($products));
        $shopify->shouldReceive('getCollectionCount')->andReturn(count($collections));
        $shopify->shouldReceive('streamProductsForSeoAudit')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback($products));
        $shopify->shouldReceive('streamCollectionsForSeoAudit')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $collections ? $callback($collections) : null);

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
        $shopify->shouldReceive('getCollectionCount')->andReturn(0);
        $shopify->shouldReceive('streamProductsForSeoAudit')
            ->once()
            ->andReturnUsing(fn (callable $cb) => $cb([$this->healthyProduct()]));
        $shopify->shouldReceive('streamCollectionsForSeoAudit')->once()->andReturnNull();

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

    public function test_fixing_sends_exactly_the_filtered_rows_for_generation(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1']),
            $this->healthyProduct([
                'id'               => '2',
                'sku'              => 'SHIRT-2',
                'meta_title'       => null,
                'meta_description' => 'A lightweight cotton overshirt with patch pockets and a '
                                    . 'straight hem, available at Test Store in Qatar.',
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('seo-audit.fix', $session), ['filter' => 'missing_meta_title'])
            ->assertRedirect();

        $content = AiContentSession::sole();

        // The clean product is not sent: paying to rewrite content that is
        // already right is the whole thing the filter exists to prevent.
        $this->assertSame(['SHIRT-2'], json_decode($content->skus_json, true));
        $this->assertSame(1, $content->total_items);
        $this->assertSame($store->id, $content->store_id);

        Bus::assertDispatched(GenerateAiContentJob::class);
    }

    public function test_fixing_stops_at_generation_and_writes_nothing_to_shopify(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);
        $session = $this->audit($user, $store, [$this->healthyProduct(['meta_title' => null])]);

        $this->actingAs($user)
            ->post(route('seo-audit.fix', $session), ['filter' => 'issues'])
            // Lands on the review screen, not back on the audit with a "done".
            ->assertRedirect(route('ai-content.show', AiContentSession::sole()));

        // The session is queued for generation, not pushed: a person still
        // decides what goes live.
        $this->assertSame('pending', AiContentSession::sole()->status);
    }

    public function test_products_without_a_sku_are_skipped_and_counted_rather_than_sent(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1', 'sku' => null, 'meta_title' => null]),
            $this->healthyProduct([
                'id'               => '2',
                'sku'              => 'SHIRT-2',
                'meta_title'       => null,
                'meta_description' => 'A lightweight cotton overshirt with patch pockets and a '
                                    . 'straight hem, available at Test Store in Qatar.',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->post(route('seo-audit.fix', $session), ['filter' => 'missing_meta_title']);

        $this->assertSame(['SHIRT-2'], json_decode(AiContentSession::sole()->skus_json, true));
        $response->assertSessionHas('success', fn ($message) => str_contains($message, '1 skipped'));
    }

    public function test_a_view_with_nothing_fixable_says_so_instead_of_opening_an_empty_session(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);
        $session = $this->audit($user, $store, [$this->healthyProduct(['sku' => null])]);

        $this->actingAs($user)
            ->post(route('seo-audit.fix', $session), ['filter' => 'all'])
            ->assertSessionHas('warning');

        $this->assertSame(0, AiContentSession::count());
        Bus::assertNothingDispatched();
    }

    public function test_an_oversized_batch_is_refused_because_generation_costs_money(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);

        $catalogue = collect(range(1, SeoAuditController::MAX_FIX_BATCH + 1))
            ->map(fn ($n) => $this->healthyProduct([
                'id'         => (string) $n,
                'sku'        => "SHIRT-{$n}",
                'meta_title' => null,
            ]))
            ->all();

        $session = $this->audit($user, $store, $catalogue);

        $this->actingAs($user)
            ->post(route('seo-audit.fix', $session), ['filter' => 'issues'])
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'batches'));

        $this->assertSame(0, AiContentSession::count());
        Bus::assertNothingDispatched();
    }

    public function test_an_unfinished_audit_cannot_be_fixed(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = SeoAuditSession::create([
            'user_id'  => $user->id,
            'store_id' => $store->id,
            'status'   => 'running',
        ]);

        $this->actingAs($user)
            ->post(route('seo-audit.fix', $session), ['filter' => 'issues'])
            ->assertSessionHas('warning');

        Bus::assertNothingDispatched();
    }

    public function test_another_users_audit_cannot_be_fixed(): void
    {
        Bus::fake();

        $owner   = $this->operator();
        $store   = $this->storeFor($owner);
        $session = $this->audit($owner, $store, [$this->healthyProduct(['meta_title' => null])]);

        $intruder = User::create([
            'name'           => 'Someone Else',
            'email'          => 'intruder@example.test',
            'password'       => 'password',
            'is_active'      => true,
            'perm_seo_audit' => true,
        ]);

        $this->actingAs($intruder)
            ->post(route('seo-audit.fix', $session))
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_a_one_word_product_title_is_flagged(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1', 'title' => 'NOBLETON']),
            $this->healthyProduct(['id' => '2', 'title' => 'Relaxed Linen Shirt', 'sku' => 'SHIRT-2']),
        ]);

        $this->assertContains(
            'title_single_word',
            $session->items()->where('product_id', '1')->first()->issues,
        );

        // Nineteen characters, three words, and a perfectly good title — a
        // length rule would have called this a problem.
        $this->assertNotContains(
            'title_single_word',
            $session->items()->where('product_id', '2')->first()->issues,
        );
    }

    public function test_collections_are_audited_alongside_products(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit(
            $user,
            $store,
            [$this->healthyProduct()],
            [$this->healthyCollection(), $this->healthyCollection(['id' => '101', 'handle' => 'hold-luggage', 'meta_title' => null])],
        );

        $this->assertSame(1, $session->scanned_products);
        $this->assertSame(2, $session->scanned_collections);
        $this->assertSame(3, $session->scannedTotal());

        $broken = $session->items()->where('product_id', '101')->first();

        $this->assertTrue($broken->isCollection());
        $this->assertSame('/collections/hold-luggage', $broken->path());
        $this->assertContains('missing_meta_title', $broken->issues);
    }

    public function test_a_collection_is_not_reported_as_having_no_images_or_tags(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit($user, $store, [], [$this->healthyCollection()]);

        // A collection has no gallery and no tags. Passing those checks
        // trivially would bury the products that genuinely have none.
        $issues = $session->items()->sole()->issues;

        $this->assertNotContains('no_images', $issues);
        $this->assertNotContains('missing_alt_text', $issues);
        $this->assertNotContains('no_tags', $issues);
        $this->assertSame([], $issues);
    }

    public function test_the_table_can_be_narrowed_to_collections_only(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit(
            $user,
            $store,
            [$this->healthyProduct(['meta_title' => null])],
            [$this->healthyCollection(['meta_title' => null])],
        );

        $response = $this->actingAs($user)
            ->getJson(route('seo-audit.items', $session) . '?filter=issues&type=collection');

        $response->assertOk();
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('collection', $response->json('items.0.resource_type'));
        $this->assertSame('/collections/cabin-luggage', $response->json('items.0.path'));
    }

    public function test_fixing_never_sends_collections_because_the_generator_needs_a_sku(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->audit(
            $user,
            $store,
            [$this->healthyProduct(['meta_title' => null])],
            [$this->healthyCollection(['meta_title' => null])],
        );

        $this->actingAs($user)->post(route('seo-audit.fix', $session), ['filter' => 'issues', 'type' => 'all']);

        // The collection is not counted as a skip either — it was never a
        // candidate, so reporting it as one would only confuse the total.
        $content = AiContentSession::sole();
        $this->assertSame(['SHIRT-1'], json_decode($content->skus_json, true));
    }

    public function test_duplicate_clusters_group_the_pages_that_clash(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $shared = 'Relaxed Linen Shirt with Camp Collar for Men';

        $session = $this->audit($user, $store, [
            $this->healthyProduct(['id' => '1', 'sku' => 'SHIRT-1', 'meta_title' => $shared]),
            $this->healthyProduct(['id' => '2', 'sku' => 'SHIRT-2', 'meta_title' => strtoupper($shared)]),
            $this->healthyProduct(['id' => '3', 'sku' => 'SHIRT-3', 'meta_title' => 'A Title Entirely Of Its Own Here']),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('seo-audit.duplicates', $session) . '?field=meta_title');

        $response->assertOk();

        // One cluster: the pair. The unique title is not a cluster of one.
        $this->assertCount(1, $response->json('clusters'));
        $this->assertSame(2, $response->json('clusters.0.pages'));

        $skus = collect($response->json('clusters.0.shown'))->pluck('sku')->sort()->values()->all();
        $this->assertSame(['SHIRT-1', 'SHIRT-2'], $skus);
    }

    public function test_a_product_and_a_collection_sharing_a_meta_title_are_one_cluster(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $shared = 'Cabin Luggage and Carry-On Suitcases in Qatar';

        $session = $this->audit(
            $user,
            $store,
            [$this->healthyProduct(['meta_title' => $shared])],
            [$this->healthyCollection(['meta_title' => $shared])],
        );

        $response = $this->actingAs($user)
            ->getJson(route('seo-audit.duplicates', $session) . '?field=meta_title');

        // They compete in the same result page regardless of what kind of page
        // each one is, so the clash is real and reported as one group.
        $this->assertCount(1, $response->json('clusters'));
        $this->assertSame(2, $response->json('clusters.0.pages'));

        $types = collect($response->json('clusters.0.shown'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['collection', 'product'], $types);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
