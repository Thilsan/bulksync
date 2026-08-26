<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Gemini refused the request because the account has nothing left to spend:
 * prepaid credits are depleted, or a free-tier daily allowance is used up.
 *
 * This is deliberately kept apart from an ordinary 429. "Slow down" clears in
 * seconds, so waiting and resending the same request works — that is what the
 * retry logic is for. An empty balance never clears, and a daily cap does not
 * clear until tomorrow, so every retry and every remaining SKU in the session
 * is a foregone failure. Left as a normal 429 it cost roughly 38 seconds per
 * SKU to rediscover, and a session simply ground through its whole list.
 */
class GeminiQuotaException extends RuntimeException
{
    public static function fromResponseBody(string $body): self
    {
        return new self(self::explain($body));
    }

    /**
     * Does this 429 describe an exhausted balance or daily allowance, rather
     * than a transient per-minute rate limit?
     *
     * Per-minute is checked first and wins: a free-tier per-minute rejection
     * often also mentions billing, and treating that as permanent would stop a
     * session that only needed to pause.
     */
    public static function matches(string $body): bool
    {
        if (preg_match('/per\s*_?minute/i', $body)) {
            return false;
        }

        return (bool) preg_match('/prepay|credits? (?:are |is )?depleted|per\s*_?day/i', $body);
    }

    private static function explain(string $body): string
    {
        if (preg_match('/prepay|credits? (?:are |is )?depleted/i', $body)) {
            return 'Gemini credits are depleted — generation stopped. Top up at https://ai.studio/projects, then run this session again.';
        }

        return 'Gemini daily free quota reached — generation stopped. It resets tomorrow, or enable billing to continue today.';
    }
}
