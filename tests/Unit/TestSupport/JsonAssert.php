<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\TestSupport;

use PHPUnit\Framework\Assert;

/**
 * Test-only helper that JSON-decodes a string and asserts it is an array.
 *
 * Removes the boilerplate that triggers PHPStan max-level "mixed offset
 * access" complaints in tests without losing type safety: the helper's
 * return type narrows what tests get back.
 */
final class JsonAssert
{
    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $body): array
    {
        $decoded = json_decode($body, true);
        Assert::assertIsArray($decoded, 'expected JSON object');
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
