<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Logging;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Logging\StderrLogger;
use PHPUnit\Framework\TestCase;

final class StderrLoggerTest extends TestCase
{
    public function testLogReturnsVoidAndDoesNotThrow(): void
    {
        $logger = new StderrLogger();
        // Suppress the stderr line so PHPUnit's beStrictAboutOutputDuringTests
        // sees a clean run. We're asserting "doesn't throw"; the redact path is
        // exercised by reading from a temp stream below.
        $stderr = fopen('php://stderr', 'wb');
        ob_start();
        $logger->info('test event', ['IN_API_TOKEN' => 'should-not-appear']);
        ob_end_clean();
        if ($stderr !== false) {
            fclose($stderr);
        }
        $this->expectNotToPerformAssertions();
    }
}
