<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Sdk;

use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoicePayload;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Sdk\EngineGateway;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSalesTax\Client as OstClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EngineGatewayTest extends TestCase
{
    /**
     * @param list<GuzzleResponse|\Throwable> $queue
     */
    private function gateway(
        array $queue,
        string $engineUrl = 'https://ost.example.com',
        bool $allowPrivate = true,
    ): EngineGateway {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);

        $ost = new OstClient(
            baseUrl: $engineUrl,
            httpClient: $guzzle,
        );
        return new EngineGateway(
            client: $ost,
            urlValidator: new UrlValidator(allowPrivateNetworks: $allowPrivate),
            engineUrl: $engineUrl,
            logger: new NullLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadArray(): array
    {
        return [
            'id' => 'Aabcd1234',
            'currency_id' => '1',
            'client' => [
                'shipping_country_id' => '840',
                'shipping_postal_code' => '55401',
            ],
            'line_items' => [['cost' => '100.00', 'quantity' => '1']],
        ];
    }

    private static function payload(): InvoicePayload
    {
        return InvoicePayload::fromArray(self::payloadArray());
    }

    public function testHappyPathReturnsResponse(): void
    {
        $engineBody = json_encode([
            'subtotal' => '100',
            'tax_total' => '8.78',
            'lines' => [[
                'amount' => '100',
                'category' => 'general',
                'tax' => '8.78',
                'rate_pct' => '8.78',
                'jurisdictions' => [
                    ['name' => 'Minnesota State', 'type' => 'state', 'rate_pct' => '6.875', 'tax' => '6.88'],
                    ['name' => 'Hennepin County', 'type' => 'county', 'rate_pct' => '1.905', 'tax' => '1.90'],
                ],
            ]],
            'disclaimer' => 'as-is',
        ], JSON_THROW_ON_ERROR);
        $gw = $this->gateway([new GuzzleResponse(200, ['Content-Type' => 'application/json'], $engineBody)]);
        $response = $gw->calculate(self::payload());
        self::assertNotNull($response);
        self::assertSame('100', $response->subtotal);
        self::assertSame('8.78', $response->taxTotal);
    }

    public function testEngineErrorReturnsNull(): void
    {
        $gw = $this->gateway([new GuzzleResponse(500, [], 'oops')]);
        self::assertNull($gw->calculate(self::payload()));
    }

    public function testMalformedEngineResponseReturnsNull(): void
    {
        $gw = $this->gateway([new GuzzleResponse(200, ['Content-Type' => 'application/json'], 'not-json')]);
        self::assertNull($gw->calculate(self::payload()));
    }

    public function testSsrfRejectedUrlReturnsNull(): void
    {
        // Reject all private/loopback when allowPrivate=false.
        $gw = $this->gateway(
            [], // no HTTP calls expected
            engineUrl: 'http://127.0.0.1:8080',
            allowPrivate: false,
        );
        self::assertNull($gw->calculate(self::payload()));
    }
}
