<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Security;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testBucketAllowsUpToCapacityImmediately(): void
    {
        $now = 0.0;
        $r = new RateLimiter(60, static function () use (&$now): float {
            return $now;
        });
        for ($i = 0; $i < 60; $i++) {
            self::assertTrue($r->allow('1.2.3.4'), "request {$i} should be allowed");
        }
        // 61st request â€” bucket empty.
        self::assertFalse($r->allow('1.2.3.4'));
    }

    public function testBucketRefillsAfterTime(): void
    {
        $now = 0.0;
        $r = new RateLimiter(60, static function () use (&$now): float {
            return $now;
        });
        // Drain the bucket.
        for ($i = 0; $i < 60; $i++) {
            $r->allow('1.2.3.4');
        }
        self::assertFalse($r->allow('1.2.3.4'));
        // Advance one second â€” at 1 token/sec, exactly one slot opens up.
        $now = 1.0;
        self::assertTrue($r->allow('1.2.3.4'));
        self::assertFalse($r->allow('1.2.3.4'));
    }

    public function testSeparateSourcesHaveIndependentBuckets(): void
    {
        $r = new RateLimiter(1, static fn (): float => 0.0);
        self::assertTrue($r->allow('1.1.1.1'));
        self::assertFalse($r->allow('1.1.1.1'));
        // Different IP â€” still allowed because its bucket is full.
        self::assertTrue($r->allow('2.2.2.2'));
    }
}
