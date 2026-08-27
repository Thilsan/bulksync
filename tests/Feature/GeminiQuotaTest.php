<?php

namespace Tests\Feature;

use App\Exceptions\GeminiQuotaException;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiQuotaTest extends TestCase
{
    /** The real body Google returned when the account's prepaid balance ran out. */
    private const DEPLETED = '{
      "error": {
        "code": 429,
        "message": "Your prepayment credits are depleted. Please go to AI Studio at https://ai.studio/projects to manage your project and billing. Learn more at https://ai.google.dev/gemini-api/docs/billing",
        "status": "RESOURCE_EXHAUSTED"
      }
    }';

    private const PER_DAY = '{
      "error": {
        "code": 429,
        "message": "You exceeded your current quota.",
        "status": "RESOURCE_EXHAUSTED",
        "details": [{"quotaId": "GenerateRequestsPerDayPerProjectPerModel-FreeTier"}]
      }
    }';

    private const PER_MINUTE = '{
      "error": {
        "code": 429,
        "message": "You exceeded your current quota.",
        "status": "RESOURCE_EXHAUSTED",
        "details": [{"quotaId": "GenerateRequestsPerMinutePerProjectPerModel-FreeTier"}]
      }
    }';

    public function test_depleted_credits_are_recognised_as_permanent(): void
    {
        $this->assertTrue(GeminiQuotaException::matches(self::DEPLETED));
        $this->assertStringContainsString(
            'ai.studio',
            GeminiQuotaException::fromResponseBody(self::DEPLETED)->getMessage(),
        );
    }

    public function test_daily_cap_is_recognised_as_permanent_for_today(): void
    {
        $this->assertTrue(GeminiQuotaException::matches(self::PER_DAY));
        $this->assertStringContainsString(
            'tomorrow',
            GeminiQuotaException::fromResponseBody(self::PER_DAY)->getMessage(),
        );
    }

    /**
     * The distinction the whole change rests on: a per-minute rejection clears
     * on its own, so it must stay retryable. Misreading it as permanent would
     * stop a session that only needed to wait a few seconds.
     */
    public function test_per_minute_rate_limit_stays_retryable(): void
    {
        $this->assertFalse(GeminiQuotaException::matches(self::PER_MINUTE));
    }

    public function test_service_stops_instead_of_retrying_a_depleted_account(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.rpm' => 6000]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(self::DEPLETED, 429),
        ]);

        $this->expectException(GeminiQuotaException::class);

        app(GeminiService::class)->generateFromTextOnly('Test Product');
    }

    /** One request, not four — the retries are the cost this change removes. */
    public function test_depleted_account_is_asked_only_once(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.rpm' => 6000]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(self::DEPLETED, 429),
        ]);

        try {
            app(GeminiService::class)->generateFromTextOnly('Test Product');
        } catch (GeminiQuotaException) {
            // expected
        }

        Http::assertSentCount(1);
    }

    /**
     * The false all-clear that cost an hour: listing models keeps working with an
     * empty balance, so a ping built on it reported "the key works" while every
     * real call was being refused, and the session ran until the worker killed it.
     * Only a call that actually spends can prove the account can spend.
     */
    public function test_ping_catches_a_depleted_balance_that_model_listing_hides(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.rpm' => 6000]);

        Http::fake([
            '*generateContent*' => Http::response(self::DEPLETED, 429),
            '*/v1beta/models'   => Http::response('{"models":[]}', 200),
        ]);

        $result = app(GeminiService::class)->ping();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ai.studio', $result['message']);
    }

    public function test_ping_passes_when_the_account_can_generate(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.rpm' => 6000]);

        Http::fake([
            '*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]]], 200),
        ]);

        $this->assertTrue(app(GeminiService::class)->ping()['ok']);
    }
}
