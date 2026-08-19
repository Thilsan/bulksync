<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Models\AiContentSession;
use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A request of twenty SKUs where ten already read well needs content for the
 * other ten — and writing over the ten that are already done is worse than doing
 * nothing at all.
 */
class ProductRequestMissingCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        $this->user = User::create([
            'name' => 'Ahamed', 'email' => 'admin@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
        ]);

        // No Cegid step: an unmatched SKU here is simply a product nobody made yet.
        $this->store = Store::create([
            'name' => 'PG Website', 'shopify_domain' => 'paris-gallery-qatar.myshopify.com',
            'is_active' => true, 'requires_sku_mapping' => false,
        ]);
    }

    /** $described live SKUs with copy, $blank live without, $absent not in Shopify. */
    private function request(int $described, int $blank, int $absent = 0): ProductRequest
    {
        $request = ProductRequest::create([
            'reference'      => ProductRequest::nextReference(),
            'user_id'        => $this->user->id,
            'store_id'       => $this->store->id,
            'request_type'   => 'new_brand',
            'brand'          => 'ARMANI BEAUTY',
            'category'       => 'Beauty',
            'status'         => ProductRequest::SKU_VERIFIED,
            'priority'       => 'medium',
            'use_ai_content' => false,
            'total_skus'     => $described + $blank + $absent,
        ]);

        $make = function (string $prefix, int $count, bool $inShopify, ?bool $hasCopy) use ($request) {
            foreach (range(1, max(0, $count)) as $i) {
                if ($count < 1) {
                    return;
                }

                ProductRequestSku::create([
                    'product_request_id' => $request->id,
                    'sku'                => "{$prefix}-{$i}",
                    'mapping_status'     => $inShopify ? ProductRequest::MAP_MAPPED : ProductRequest::MAP_PENDING,
                    'in_shopify'         => $inShopify,
                    'has_description'    => $hasCopy,
                ]);
            }
        };

        $make('HAS', $described, true, true);
        $make('BLANK', $blank, true, false);
        $make('ABSENT', $absent, false, null);

        return $request;
    }

    public function test_only_the_skus_with_no_copy_are_counted(): void
    {
        $request = $this->request(described: 10, blank: 10);

        $this->assertSame(10, $request->missingDescriptionCount());
        $this->assertSame(10, $request->needsContentCount());
        $this->assertSame(10, $request->describedCount());
        $this->assertSame(10, $request->contentHandledCount());
        $this->assertTrue($request->canOfferContentForMissing());
    }

    /** The whole point: the ten that already read well are not touched. */
    public function test_generating_covers_only_the_skus_with_no_copy(): void
    {
        $request = $this->request(described: 10, blank: 10);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope'  => 'missing_description',
                'answer' => 'generate',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $session = AiContentSession::sole();
        $skus    = json_decode($session->skus_json, true);

        $this->assertCount(10, $skus);
        foreach ($skus as $sku) {
            $this->assertStringStartsWith('BLANK-', $sku, 'A SKU that already has copy must be left alone.');
        }

        Queue::assertPushed(GenerateAiContentJob::class);
        $this->assertSame('generate', $request->refresh()->ai_content_decision);
    }

    /** A SKU not in Shopify has no product to write copy onto. */
    public function test_skus_not_in_shopify_are_not_included(): void
    {
        $request = $this->request(described: 0, blank: 2, absent: 5);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope'  => 'missing_description',
                'answer' => 'generate',
            ])
            ->assertRedirect();

        $this->assertCount(2, json_decode(AiContentSession::sole()->skus_json, true));
    }

    public function test_turning_the_offer_down_is_recorded(): void
    {
        $request = $this->request(described: 10, blank: 10);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope'  => 'missing_description',
                'answer' => 'skip',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request->refresh();
        $this->assertSame('skip', $request->ai_content_decision);
        $this->assertSame(0, AiContentSession::count());
        Queue::assertNotPushed(GenerateAiContentJob::class);
    }

    /** Being asked again about the same SKUs is its own kind of broken. */
    public function test_the_offer_is_not_made_twice_for_the_same_skus(): void
    {
        $request = $this->request(described: 10, blank: 10);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope' => 'missing_description', 'answer' => 'skip',
            ])->assertRedirect();

        $this->assertFalse($request->refresh()->canOfferContentForMissing());
    }

    /**
     * The case per-SKU tracking exists for. 30 SKUs on a Cegid website: 28 mapped
     * and generated for, then someone re-validates and the last 2 map too. Those
     * 2 need copy — and only those 2.
     */
    public function test_skus_mapped_after_a_generation_are_offered_on_their_own(): void
    {
        $request = $this->request(described: 0, blank: 28, absent: 2);

        // Copy written for the 28 that were live at the time.
        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope' => 'missing_description', 'answer' => 'generate',
            ])->assertRedirect();

        $this->assertCount(28, json_decode(AiContentSession::sole()->skus_json, true));
        $this->assertFalse($request->refresh()->canOfferContentForMissing(), 'Nothing is outstanding yet.');

        // Validate SKUs is pressed and the last 2 come back mapped and live.
        $request->skus()->where('sku', 'like', 'ABSENT-%')->update([
            'mapping_status'  => ProductRequest::MAP_MAPPED,
            'in_shopify'      => true,
            'has_description' => false,
        ]);

        $request->refresh();
        $this->assertTrue($request->canOfferContentForMissing(), 'The 2 that just landed are a fresh question.');
        $this->assertSame(2, $request->needsContentCount());
        $this->assertSame(28, $request->contentHandledCount());

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope' => 'missing_description', 'answer' => 'generate',
            ])->assertRedirect();

        // A second session, covering the 2 only — the 28 are not rewritten.
        $second = AiContentSession::orderByDesc('id')->first();
        $skus   = json_decode($second->skus_json, true);

        $this->assertCount(2, $skus);
        foreach ($skus as $sku) {
            $this->assertStringStartsWith('ABSENT-', $sku);
        }
    }

    /** Skipping a batch does not silence the offer for SKUs mapped afterwards. */
    public function test_skipping_does_not_silence_skus_that_arrive_later(): void
    {
        $request = $this->request(described: 0, blank: 5, absent: 2);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope' => 'missing_description', 'answer' => 'skip',
            ])->assertRedirect();

        $this->assertFalse($request->refresh()->canOfferContentForMissing());

        $request->skus()->where('sku', 'like', 'ABSENT-%')->update([
            'in_shopify' => true, 'has_description' => false,
        ]);

        $this->assertTrue($request->refresh()->canOfferContentForMissing());
        $this->assertSame(2, $request->needsContentCount());
    }

    public function test_a_request_whose_copy_is_all_written_is_never_asked(): void
    {
        $request = $this->request(described: 20, blank: 0);

        $this->assertFalse($request->canOfferContentForMissing());

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope'  => 'missing_description',
                'answer' => 'generate',
            ])
            ->assertSessionHasErrors('ai');
    }

    /**
     * "The brand team supplies the copy" is about the descriptions they wrote,
     * not about the ten SKUs nobody has touched.
     */
    public function test_a_brand_supplied_request_can_still_fill_the_blanks(): void
    {
        $request = $this->request(described: 10, blank: 10);
        $this->assertFalse((bool) $request->use_ai_content);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), [
                'scope'  => 'missing_description',
                'answer' => 'generate',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertCount(10, json_decode(AiContentSession::sole()->skus_json, true));
    }

    /** But the everything path still respects it. */
    public function test_a_brand_supplied_request_refuses_the_everything_path(): void
    {
        $request = $this->request(described: 10, blank: 10);

        $this->actingAs($this->user)
            ->post(route('product-requests.ai-content', $request), ['scope' => 'all'])
            ->assertSessionHasErrors('ai');
    }

    // ── The banner offers the generator instead of only a sheet ──────────────

    /**
     * "This request is not using the AI Content Generator" was a dead end: the
     * setting says where the copy was meant to come from, not whether it exists.
     */
    public function test_a_brand_supplied_request_with_blank_products_is_offered_the_generator(): void
    {
        $request = $this->request(described: 2, blank: 10);

        $this->assertTrue($request->awaitingContentSheet());
        $this->assertTrue($request->couldGenerateInsteadOfSheet());

        $this->actingAs($this->user)
            ->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('10 product(s) are live with no description')
            ->assertSee('Generate AI content for 10');
    }

    /** With every description written, there is nothing to generate. */
    public function test_a_request_whose_products_all_have_copy_is_only_asked_for_a_sheet(): void
    {
        $request = $this->request(described: 12, blank: 0);

        $this->assertFalse($request->couldGenerateInsteadOfSheet());

        $this->actingAs($this->user)
            ->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('The brand team needs to upload the copy');
    }

    /**
     * Null is "nobody looked", not "no copy" — the case every request validated
     * before the column existed is in. Saying so beats offering to write over
     * descriptions that may well be there.
     */
    public function test_unchecked_descriptions_are_reported_as_needing_a_check(): void
    {
        $request = $this->request(described: 0, blank: 0);

        foreach (range(1, 12) as $i) {
            ProductRequestSku::create([
                'product_request_id' => $request->id,
                'sku'                => "OLD-{$i}",
                'mapping_status'     => ProductRequest::MAP_MAPPED,
                'in_shopify'         => true,
                'has_description'    => null,
            ]);
        }

        $this->assertSame(12, $request->descriptionsUncheckedCount());
        $this->assertSame(0, $request->needsContentCount());
        $this->assertFalse($request->canOfferContentForMissing());

        $this->actingAs($this->user)
            ->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('has not read the existing descriptions back yet');
    }

    /** The backfill finds exactly those requests, and touches nothing on a dry run. */
    public function test_the_backfill_reports_requests_whose_descriptions_were_never_read(): void
    {
        $request = $this->request(described: 0, blank: 0);

        ProductRequestSku::create([
            'product_request_id' => $request->id,
            'sku'                => 'OLD-1',
            'mapping_status'     => ProductRequest::MAP_MAPPED,
            'in_shopify'         => true,
            'has_description'    => null,
        ]);

        $this->artisan('product-requests:backfill-descriptions')
            ->expectsOutputToContain("Would re-check {$request->reference}")
            ->assertSuccessful();

        $this->assertSame(1, $request->descriptionsUncheckedCount(), 'A dry run must change nothing.');
    }

    public function test_the_backfill_skips_requests_already_checked(): void
    {
        $this->request(described: 5, blank: 5);

        $this->artisan('product-requests:backfill-descriptions')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }

    public function test_publishing_reports_skus_left_without_a_description(): void
    {
        $request = $this->request(described: 10, blank: 10);
        $request->update(['status' => ProductRequest::QA_REVIEW]);

        $this->assertStringContainsString(
            '10 SKU(s) live in Shopify with no description',
            implode(' ', $request->publishGaps()),
        );
    }
}
