<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Security;

/**
 * Token-bucket rate limiter, in-memory.
 *
 * One bucket per source identifier (the remote IP, by default). The bucket
 * refills `capacity` tokens per 60-second window. Requests beyond capacity
 * are rejected.
 *
 * In-memory state means rate limits reset on process restart. For a
 * multi-process / multi-replica deployment, this needs Redis/Memcached
 * backing — flagged in `docs/SECURITY-REVIEW.md` as a v0.2 follow-up.
 */
final class RateLimiter
{
    /** @var array<string, array{tokens: float, last: float}> */
    private array $buckets = [];

    public function __construct(
        private readonly int $capacityPerMinute,
        /** @var callable(): float */
        private $clock = null,
    ) {
        if ($this->clock === null) {
            $this->clock = static fn (): float => microtime(true);
        }
    }

    /**
     * Returns true if the request fits in the bucket (allow + decrement).
     * Returns false if the bucket is empty (reject).
     */
    public function allow(string $sourceId): bool
    {
        $now = ($this->clock)();
        $refillRate = $this->capacityPerMinute / 60.0; // tokens per second

        if (!isset($this->buckets[$sourceId])) {
            $this->buckets[$sourceId] = ['tokens' => (float) $this->capacityPerMinute, 'last' => $now];
        }

        $bucket = $this->buckets[$sourceId];
        $elapsed = max(0.0, $now - $bucket['last']);
        $tokens = min(
            (float) $this->capacityPerMinute,
            $bucket['tokens'] + ($elapsed * $refillRate),
        );

        if ($tokens < 1.0) {
            $this->buckets[$sourceId] = ['tokens' => $tokens, 'last' => $now];
            return false;
        }
        $this->buckets[$sourceId] = ['tokens' => $tokens - 1.0, 'last' => $now];
        return true;
    }
}
