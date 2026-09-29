<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Models\AiContentSession;
use App\Models\Store;
use App\Models\User;
use App\Services\SearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generation writes from a photograph, which tells it what the product looks
 * like and nothing about what anyone is trying to find. These are the terms
 * handed to it to close that gap: what the merchant typed, and what the page
 * is already being shown for.
 */
class SeoKeywordGroundingTest extends TestCase
{
    use RefreshDatabase;

    private function contentSession(?Store $store, ?string $keywords): AiContentSession
    {
        $user = User::create([
            'name'      => 'Content Writer',
            'email'     => 'writer@example.test',
            'password'  => 'password',
            'is_active' => true,
        ]);

        return AiContentSession::create([
            'user_id'     => $user->id,
            'store_id'    => $store?->id,
            'input_type'  => 'sku_list',
            'sku_raw'     => 'SHIRT-1',
            'skus_json'   => json_encode(['SHIRT-1']),
            'keywords'    => $keywords,
            'status'      => 'pending',
            'total_items' => 1,
        ]);
    }

    private function storeWithSearchConsole(?string $site = 'sc-domain:test.com'): Store
    {
        return Store::create([
            'name'                 => 'Test Store',
            'shopify_domain'       => 'test.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'gsc_site_url'         => $site,
        ]);
    }

    /** Reaches the job's private resolver with Search Console faked out. */
    private function termsFor(AiContentSession $session, string $handle, array $queries = [], bool $throws = false): array
    {
        $console = new SearchConsoleService(function () use ($queries, $throws) {
            if ($throws) {
                throw new \RuntimeException('Search Console query failed (HTTP 403): no access');
            }

            return array_map(fn ($q) => ['keys' => [$q], 'impressions' => 10, 'clicks' => 1, 'position' => 5.0], $queries);
        });

        $job = new class($session->id, $console) extends GenerateAiContentJob {
            public function __construct(int $id, private $console)
            {
                parent::__construct($id);
            }

            protected function searchConsole(): SearchConsoleService
            {
                return $this->console;
            }

            public function termsFor(AiContentSession $session, string $handle): array
            {
                return $this->searchTermsFor($session, $handle);
            }
        };

        return $job->termsFor($session, $handle);
    }

    public function test_typed_keywords_are_split_on_commas_and_newlines(): void
    {
        $session = $this->contentSession(null, "cabin suitcase, carry-on luggage\nhand luggage qatar");

        $this->assertSame(
            ['cabin suitcase', 'carry-on luggage', 'hand luggage qatar'],
            $this->termsFor($session, 'linen-shirt'),
        );
    }

    public function test_real_search_terms_are_added_to_the_typed_ones(): void
    {
        $session = $this->contentSession($this->storeWithSearchConsole(), 'cabin suitcase');

        $terms = $this->termsFor($session, 'linen-shirt', ['spinner suitcase', 'tsa lock luggage']);

        // The merchant's term leads — it is the deliberate one — and what
        // people really type is added behind it.
        $this->assertSame(['cabin suitcase', 'spinner suitcase', 'tsa lock luggage'], $terms);
    }

    public function test_the_same_term_typed_and_reported_is_not_handed_over_twice(): void
    {
        $session = $this->contentSession($this->storeWithSearchConsole(), 'Cabin Suitcase');

        $terms = $this->termsFor($session, 'linen-shirt', ['cabin suitcase', 'spinner suitcase']);

        // Two spellings of one term would read to the model as emphasis, and
        // emphasis is how keyword stuffing starts.
        $this->assertSame(['Cabin Suitcase', 'spinner suitcase'], $terms);
    }

    public function test_a_store_without_search_console_just_uses_what_was_typed(): void
    {
        $session = $this->contentSession($this->storeWithSearchConsole(site: null), 'cabin suitcase');

        $this->assertSame(['cabin suitcase'], $this->termsFor($session, 'linen-shirt', ['never asked for']));
    }

    public function test_a_product_with_no_handle_cannot_be_looked_up_and_is_not_tried(): void
    {
        $session = $this->contentSession($this->storeWithSearchConsole(), 'cabin suitcase');

        $this->assertSame(['cabin suitcase'], $this->termsFor($session, '', ['never asked for']));
    }

    public function test_search_console_failing_does_not_stop_the_generation(): void
    {
        $session = $this->contentSession($this->storeWithSearchConsole(), 'cabin suitcase');

        // A revoked grant is a reason to write without the extra terms, not a
        // reason to abandon a run that would otherwise produce good content.
        $this->assertSame(['cabin suitcase'], $this->termsFor($session, 'linen-shirt', throws: true));
    }

    public function test_nothing_typed_and_nothing_connected_means_no_terms_at_all(): void
    {
        $session = $this->contentSession(null, null);

        // The prompt then behaves exactly as it did before any of this existed.
        $this->assertSame([], $this->termsFor($session, 'linen-shirt'));
    }
}
