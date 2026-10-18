<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI SEO Support: the screens in front of the easybits SEO engine.
 *
 * What is worth holding still: the engine is only ever asked about a store
 * that is connected and a page on that store's own domain, a failure from it
 * reads as a retry rather than a crash, and only people given the feature see
 * any of it.
 */
class AiSeoSupportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://bisdev.dev.easybits.tech/webhook';

    private function user(array $overrides = []): User
    {
        // is_active matters: an inactive user is bounced to the login screen.
        return User::create(array_merge([
            'name'        => 'SEO',
            'email'       => uniqid() . '@example.test',
            'password'    => 'password',
            'is_active'   => true,
            'perm_ai_seo' => true,
        ], $overrides));
    }

    private function quickWinsResponse(): array
    {
        return [
            'summary' => [
                'hasData' => true, 'totalPages' => 412, 'quickWins' => 1,
                'strikingDistance' => 1, 'lowCtr' => 0, 'generatedAt' => '2026-10-14T09:12:00.000Z',
            ],
            'quickWins' => [[
                'url' => 'https://goldgourmet.qa/products/saffron-gift-box', 'type' => 'Striking distance',
                'severity' => 'high', 'score' => 148, 'position' => 7.4, 'impressions' => 920,
                'clicks' => 31, 'ctr' => 3.4, 'why' => 'Ranking 7.4 with 920 impressions.',
            ]],
        ];
    }

    public function test_quick_wins_are_listed_for_the_chosen_store(): void
    {
        Http::fake([self::BASE . '/seo-quickwins' => Http::response($this->quickWinsResponse())]);

        $this->actingAs($this->user())
            ->get(route('ai-seo.index', ['property' => 'sc-domain:goldgourmet.qa']))
            ->assertOk()
            ->assertSee('/products/saffron-gift-box')
            ->assertSee('Ranking 7.4 with 920 impressions.')
            ->assertSee('Gold Gourmet');

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE . '/seo-quickwins'
            && $r['property'] === 'sc-domain:goldgourmet.qa'
            && !$r->hasHeader('Authorization'));
    }

    /** An unknown store is not passed through; the first connected one is used. */
    public function test_an_unknown_store_falls_back_to_a_connected_one(): void
    {
        Http::fake([self::BASE . '/seo-quickwins' => Http::response($this->quickWinsResponse())]);

        $this->actingAs($this->user())
            ->get(route('ai-seo.index', ['property' => 'sc-domain:example.com']))
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r['property'] === 'sc-domain:goldgourmet.qa');
    }

    public function test_no_search_console_data_yet_is_explained_not_an_empty_table(): void
    {
        Http::fake([self::BASE . '/seo-quickwins' => Http::response(['summary' => ['hasData' => false], 'quickWins' => []])]);

        $this->actingAs($this->user())
            ->get(route('ai-seo.index'))
            ->assertOk()
            ->assertSee('Search Console has no data');
    }

    public function test_an_engine_failure_offers_a_retry(): void
    {
        Http::fake([self::BASE . '/seo-quickwins' => Http::response(['message' => 'boom'], 500)]);

        $this->actingAs($this->user())
            ->get(route('ai-seo.index'))
            ->assertOk()
            ->assertSee('returned an error (500): boom')
            ->assertSee('Try again');
    }

    /** What the live engine actually sent on 18 Oct 2026: a 200 with no body. */
    public function test_an_empty_answer_says_the_engine_sent_nothing(): void
    {
        Http::fake([self::BASE . '/*' => Http::response('', 200, ['Content-Type' => 'application/json'])]);

        $this->actingAs($this->user())
            ->get(route('ai-seo.index'))
            ->assertOk()
            ->assertSee('sent back nothing');

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.report'), [
                'url' => 'https://goldgourmet.qa/', 'property' => 'sc-domain:goldgourmet.qa',
            ])
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'sent back nothing'));
    }

    public function test_the_page_report_is_fetched_for_a_page_on_the_store(): void
    {
        Http::fake([self::BASE . '/seo-optimizer' => Http::response([
            'summary'  => ['score' => 68, 'findingsCount' => 1, 'resolvedCount' => 0, 'url' => 'https://goldgourmet.qa/products/x'],
            'sections' => [['category' => 'Title', 'findings' => [['findingId' => 'a3f9c21b', 'issue' => 'Title too long']]]],
        ])]);

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.report'), [
                'url' => 'https://goldgourmet.qa/products/x', 'property' => 'sc-domain:goldgourmet.qa',
            ])
            ->assertOk()
            ->assertJsonPath('summary.score', 68)
            ->assertJsonPath('sections.0.findings.0.findingId', 'a3f9c21b');
    }

    /**
     * The engine fetches whatever page it is given, so it is only ever pointed
     * at the store's own domain.
     */
    public function test_a_page_from_another_site_is_refused_before_the_engine_is_called(): void
    {
        Http::fake();

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.report'), [
                'url' => 'https://outoftheblue.qa/products/x', 'property' => 'sc-domain:goldgourmet.qa',
            ])
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.report'), [
                'url' => 'https://evil-goldgourmet.qa.example.com/', 'property' => 'sc-domain:goldgourmet.qa',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_subdomain_of_the_store_is_allowed(): void
    {
        Http::fake([self::BASE . '/seo-optimizer' => Http::response(['summary' => ['score' => 90], 'sections' => []])]);

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.report'), [
                'url' => 'https://www.goldgourmet.qa/ar/products/x', 'property' => 'sc-domain:goldgourmet.qa',
            ])
            ->assertOk();
    }

    public function test_a_slow_engine_comes_back_as_a_retryable_error(): void
    {
        Http::fake([self::BASE . '/seo-optimizer' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.report'), [
                'url' => 'https://goldgourmet.qa/products/x', 'property' => 'sc-domain:goldgourmet.qa',
            ])
            ->assertStatus(502)
            ->assertJsonPath('message', 'The SEO engine did not answer in time. It is safe to try again.');
    }

    public function test_marking_resolved_sends_all_five_fields(): void
    {
        Http::fake([self::BASE . '/seo-mark-resolved' => Http::response(['ok' => true, 'saved' => []])]);

        $finding = [
            'findingId' => 'a3f9c21b',
            'url'       => 'https://goldgourmet.qa/products/saffron-gift-box',
            'property'  => 'sc-domain:goldgourmet.qa',
            'issue'     => 'Title too long (71 chars)',
            'category'  => 'Title',
        ];

        $this->actingAs($this->user())
            ->postJson(route('ai-seo.resolve'), $finding)
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE . '/seo-mark-resolved' && $r->data() === $finding);
    }

    public function test_the_analyzer_page_says_when_a_pasted_page_is_on_the_wrong_store(): void
    {
        $this->actingAs($this->user())
            ->get(route('ai-seo.analyze', ['property' => 'sc-domain:goldgourmet.qa', 'url' => 'https://outoftheblue.qa/x']))
            ->assertOk()
            ->assertSee('That page is not on goldgourmet.qa');
    }

    public function test_without_the_feature_there_is_no_access_and_no_menu_entry(): void
    {
        Http::fake();
        $user = $this->user(['perm_ai_seo' => false]);

        $this->actingAs($user)->get(route('ai-seo.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('ai-seo.report'), [
            'url' => 'https://goldgourmet.qa/', 'property' => 'sc-domain:goldgourmet.qa',
        ])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee('AI SEO Support');

        Http::assertNothingSent();
    }

    public function test_the_sidebar_shows_it_as_its_own_entry(): void
    {
        Http::fake([self::BASE . '/seo-quickwins' => Http::response($this->quickWinsResponse())]);

        $this->actingAs($this->user())
            ->get(route('ai-seo.index'))
            ->assertSee(route('ai-seo.index'), false)
            ->assertSee('AI SEO Support');
    }
}
