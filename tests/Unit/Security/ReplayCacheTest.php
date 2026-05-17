<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Security;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\ReplayCache;
use PHPUnit\Framework\TestCase;

final class ReplayCacheTest extends TestCase
{
    public function testFirstSeenIsAccepted(): void
    {
        $c = new ReplayCache(ttlSeconds: 60, clock: static fn (): int => 1_000);
        self::assertTrue($c->checkAndRemember(1_000, '{"x":1}'));
        self::assertSame(1, $c->size());
    }

    public function testDuplicateWithinTtlIsRejected(): void
    {
        $c = new ReplayCache(ttlSeconds: 60, clock: static fn (): int => 1_000);
        self::assertTrue($c->checkAndRemember(1_000, '{"x":1}'));
        self::assertFalse($c->checkAndRemember(1_000, '{"x":1}'));
    }

    public function testDifferentTimestampSameBodyIsAccepted(): void
    {
        $c = new ReplayCache(ttlSeconds: 60, clock: static fn (): int => 1_000);
        self::assertTrue($c->checkAndRemember(1_000, '{"x":1}'));
        self::assertTrue($c->checkAndRemember(1_001, '{"x":1}'));
    }

    public function testSameTimestampDifferentBodyAccepted(): void
    {
        $c = new ReplayCache(ttlSeconds: 60, clock: static fn (): int => 1_000);
        self::assertTrue($c->checkAndRemember(1_000, '{"a":1}'));
        self::assertTrue($c->checkAndRemember(1_000, '{"b":2}'));
    }

    public function testBoundedCacheEvictsOldestWhenAtCapacity(): void
    {
        $now = 1_000;
        $c = new ReplayCache(
            ttlSeconds: 86_400,
            maxEntries: 3,
            clock: static function () use (&$now): int {
                return $now;
            },
        );
        self::assertTrue($c->checkAndRemember(1_000, 'a'));
        $now = 1_001;
        self::assertTrue($c->checkAndRemember(1_001, 'b'));
        $now = 1_002;
        self::assertTrue($c->checkAndRemember(1_002, 'c'));
        // Cache is at capacity (3 entries). The next fresh entry should drop 'a'.
        $now = 1_003;
        self::assertTrue($c->checkAndRemember(1_003, 'd'));
        self::assertSame(3, $c->size());
        // 'a' was evicted â€” re-submit should be considered fresh.
        $now = 1_004;
        self::assertTrue($c->checkAndRemember(1_000, 'a'));
    }
}
