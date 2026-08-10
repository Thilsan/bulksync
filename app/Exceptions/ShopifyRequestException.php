<?php

namespace App\Exceptions;

use GuzzleHttp\Exception\ClientException;
use RuntimeException;

/**
 * A rejected Shopify API call, carrying whether it is worth trying again.
 *
 * A 422 ("the pixel limit is 20 megapixels") means the request itself is
 * wrong — resending the identical bytes gets the identical rejection, so the
 * queue should stop rather than spend its retries and its rate limit on a
 * foregone conclusion. A 429 or a request timeout is the opposite: the same
 * request a moment later usually succeeds.
 */
class ShopifyRequestException extends RuntimeException
{
    /** Status codes where waiting and resending the same request can work. */
    private const RETRYABLE_STATUSES = [408, 429];

    public function __construct(
        string $message,
        private readonly int $status,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromClientException(ClientException $e, string $message): self
    {
        return new self($message, $e->getResponse()->getStatusCode(), $e);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isPermanent(): bool
    {
        return !in_array($this->status, self::RETRYABLE_STATUSES, true);
    }
}
