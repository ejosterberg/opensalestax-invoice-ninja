<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\InvoiceNinja;

use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoiceNinjaClient;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InvoiceNinjaClientTest extends TestCase
{
    /**
     * @param list<GuzzleResponse|\Throwable> $queue
     */
    private function clientWithQueue(array $queue): InvoiceNinjaClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack]);
        return new InvoiceNinjaClient(
            baseUrl: 'https://invoices.example.com',
            apiToken: 'token-xyz',
            urlValidator: new UrlValidator(allowPrivateNetworks: true),
            http: $guzzle,
            logger: new NullLogger(),
        );
    }

    public function testSuccessfulPutReturnsTrue(): void
    {
        $client = $this->clientWithQueue([new GuzzleResponse(200, [], '{"data":{}}')]);
        self::assertTrue($client->applyTaxToInvoice('Aabcd1234', 'OpenSalesTax', 7.875));
    }

    public function testConflictTriggersRetryAndSucceeds(): void
    {
        $client = $this->clientWithQueue([
            new GuzzleResponse(409, [], 'conflict'),
            new GuzzleResponse(200, [], '{"data":{}}'),
        ]);
        self::assertTrue($client->applyTaxToInvoice('Aabcd1234', 'OpenSalesTax', 7.875));
    }

    public function testRepeatedConflictReturnsFalse(): void
    {
        $client = $this->clientWithQueue([
            new GuzzleResponse(409, [], 'conflict'),
            new GuzzleResponse(409, [], 'conflict'),
        ]);
        self::assertFalse($client->applyTaxToInvoice('Aabcd1234', 'OpenSalesTax', 7.875));
    }

    public function testServerErrorReturnsFalseWithoutRetry(): void
    {
        $client = $this->clientWithQueue([
            new GuzzleResponse(500, [], 'oops'),
        ]);
        self::assertFalse($client->applyTaxToInvoice('Aabcd1234', 'OpenSalesTax', 7.875));
    }

    public function testTransportErrorReturnsFalse(): void
    {
        $client = $this->clientWithQueue([
            new ConnectException(
                'connection refused',
                new GuzzleRequest('PUT', 'https://invoices.example.com/api/v1/invoices/Aabcd1234'),
            ),
        ]);
        self::assertFalse($client->applyTaxToInvoice('Aabcd1234', 'OpenSalesTax', 7.875));
    }

    public function testInvalidInvoiceIdRejected(): void
    {
        $client = $this->clientWithQueue([new GuzzleResponse(200, [], '{}')]);
        $this->expectException(InvalidArgumentException::class);
        // Invoice id with path traversal: must be rejected before any HTTP call.
        $client->applyTaxToInvoice('../etc/passwd', 'OpenSalesTax', 7.0);
    }

    public function testLongInvoiceIdRejected(): void
    {
        $client = $this->clientWithQueue([]);
        $this->expectException(InvalidArgumentException::class);
        $client->applyTaxToInvoice(str_repeat('a', 100), 'OpenSalesTax', 7.0);
    }
}
