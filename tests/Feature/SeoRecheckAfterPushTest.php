<?php

namespace Tests\Feature;

use App\Jobs\RecheckSeoAfterPushJob;
use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Models\User;
use App\Services\ShopifyService;
use App\Support\SeoGrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * After an AI content push, the pushed products are read back from Shopify and
 * graded by the audit's own rules — the batch gets a before and after, and the
 * audit stops listing work that has already been done.
 */
class SeoRecheckAfterPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;
    private AiContentSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::create(['name' => 'Merch', 'email' => 'merch@example.test', 'password' => 'password', 'is_active' => true]);
        $this->store = Store::create(['name' => 'Paris Gallery', 'shopify_domain' => 'pg.myshopify.com', 'is_active' => true]);

        $this->session = AiContentSession::create([
            'user_id' => $this->user->id, 'store_id' => $this->store->id, 'input_type' => 'sku_list',
            'status' => 'rechecking', 'total_items' => 0, 'processed_items' => 0,
        ]);
    }

    /** A product as getProductsForSeoAudit() returns it, with everything right. */
    private function healthy(string $id, array $overrides = []): array
    {
        return array_merge([
            'id'               => $id,
            'title'            => "Relaxed Linen Shirt {$id}",
            'handle'           => "shirt-{$id}",
            'status'           => 'active',
            'tags'             => ['shirts'],
            'description'      => str_repeat('A well written product description. ', 10),
            'meta_title'       => "Relaxed Linen Shirt {$id} with Camp Collar for Men",
            'meta_description' => "A breathable relaxed-fit linen shirt {$id} with a camp collar and mother-of-pearl buttons, in Qatar.",
            'images'           => [['alt' => 'Light blue linen shirt']],
            'sku'              => "SKU-{$id}",
        ], $overrides);
    }

    /** An audit row, graded from a product exactly as the full audit would. */
    private function auditRow(SeoAuditSession $audit, array $product, array $extraIssues = []): SeoAuditItem
    {
        $row = SeoGrader::product($product);
        $row['issues'] = array_merge($row['issues'], $extraIssues);
        $row = SeoGrader::finalise($row);

        return SeoAuditItem::create($row + ['seo_audit_session_id' => $audit->id]);
    }

    private function pushed(string $productId): AiContentItem
    {
        return AiContentItem::create([
            'session_id' => $this->session->id, 'sku' => "SKU-{$productId}", 'shopify_product_id' => $productId,
            'product_title' => "Shirt {$productId}", 'status' => 'pushed',
        ]);
    }

    private function shopifyReturns(array $products): void
    {
        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductsForSeoAudit')->andReturn(collect($products)->keyBy('id')->all());
        $this->app->bind(ShopifyService::class, fn () => $shopify);
    }

    private function audit(): SeoAuditSession
    {
        return SeoAuditSession::create(['user_id' => $this->user->id, 'store_id' => $this->store->id, 'status' => 'completed']);
    }

    public function test_the_batch_gets_a_before_and_after_and_the_audit_is_updated(): void
    {
        $audit = $this->audit();

        // Before the push: no meta fields, and B shares its old meta title with D.
        $this->auditRow($audit, $this->healthy('A', ['meta_title' => '', 'meta_description' => '']));
        $this->auditRow($audit, $this->healthy('B', ['meta_title' => 'Shirt']), ['duplicate_meta_title']);
        $this->auditRow($audit, $this->healthy('D', ['meta_title' => 'Shirt']), ['duplicate_meta_title']);
        // C was never pushed. A's new meta title will clash with it.
        $this->auditRow($audit, $this->healthy('C', ['meta_title' => 'Linen Shirt for Men in Qatar Camp Collar']));
        $audit->recount();

        $a = $this->pushed('A');
        $b = $this->pushed('B');

        $this->shopifyReturns([
            $this->healthy('A', ['meta_title' => 'Linen Shirt for Men in Qatar Camp Collar']),
            $this->healthy('B'),
        ]);

        (new RecheckSeoAfterPushJob($this->session->id))->handle();

        $this->session->refresh();
        $this->assertSame('done', $this->session->status);

        // B is now clean; A gained its meta fields but now clashes with C.
        $b->refresh();
        $this->assertSame(100, $b->seo_score_after);
        $this->assertSame([], $b->seo_issues_after);
        $this->assertSame(['meta_title_too_short', 'duplicate_meta_title'], $b->seo_issues_before);

        $a->refresh();
        $this->assertSame(['duplicate_meta_title'], $a->seo_issues_after);
        $this->assertSame(['missing_meta_title', 'missing_meta_description'], $a->seo_issues_before);

        // The audit rows moved with them, on both sides of each clash.
        $row = fn ($id) => $audit->items()->where('product_id', $id)->first();
        $this->assertContains('duplicate_meta_title', $row('C')->issues, 'C now shares a title with A.');
        $this->assertNotContains('duplicate_meta_title', $row('D')->issues, 'D no longer shares its title with anyone.');

        $audit->refresh();
        $this->assertNotNull($audit->rechecked_at);
        $this->assertSame(2, $audit->rechecked_products);
        $this->assertSame(['duplicate_meta_title' => 2, 'meta_title_too_short' => 1], $audit->issue_breakdown);

        $summary = $this->session->seo_recheck;
        $this->assertSame($audit->id, $summary['audit_id']);
        $this->assertSame(2, $summary['checked']);
        $this->assertSame(1, $summary['clean_after']);
        $this->assertSame(0, $summary['clean_before']);
        $this->assertSame(1, $summary['still_failing']);
        $this->assertSame(4, $summary['issues_fixed']);
        $this->assertSame(['duplicate_meta_title' => 1], $summary['remaining']);
        $this->assertSame('SKU-A', $summary['failing_items'][0]['sku']);
    }

    public function test_without_an_audit_the_batch_is_still_graded(): void
    {
        $item = $this->pushed('A');
        $this->shopifyReturns([$this->healthy('A', ['images' => []])]);

        (new RecheckSeoAfterPushJob($this->session->id))->handle();

        $item->refresh();
        $this->assertNull($item->seo_score_before);
        $this->assertSame(['no_images'], $item->seo_issues_after);

        $summary = $this->session->refresh()->seo_recheck;
        $this->assertNull($summary['audit_id']);
        $this->assertNull($summary['score_before']);
        $this->assertSame(['no_images' => 1], $summary['remaining']);
    }

    public function test_a_failed_check_still_finishes_the_session(): void
    {
        $this->pushed('A');

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductsForSeoAudit')->andThrow(new \RuntimeException('Shopify is down'));
        $this->app->bind(ShopifyService::class, fn () => $shopify);

        (new RecheckSeoAfterPushJob($this->session->id))->handle();

        $this->session->refresh();
        $this->assertSame('done', $this->session->status);
        $this->assertSame('Shopify is down', $this->session->seo_recheck['error']);
    }

    public function test_the_review_page_shows_the_result(): void
    {
        $this->session->update(['status' => 'done', 'seo_recheck' => [
            'checked' => 100, 'score_before' => 41, 'score_after' => 87, 'audit_id' => null,
        ]]);

        $this->actingAs($this->user)
            ->get(route('ai-content.show', $this->session))
            ->assertOk()
            ->assertSee('SEO re-check after push')
            ->assertSee('score_after\\u0022:87', false);
    }
}
