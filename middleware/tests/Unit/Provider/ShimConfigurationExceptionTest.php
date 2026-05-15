<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Provider;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\ShimConfigurationException;
use PHPUnit\Framework\TestCase;

final class ShimConfigurationExceptionTest extends TestCase
{
    public function testIsRuntimeException(): void
    {
        $e = new ShimConfigurationException('oops');
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('oops', $e->getMessage());
    }
}
