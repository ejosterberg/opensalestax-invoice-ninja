<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Service;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Http\Request;
use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoiceNinjaClient;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Sdk\EngineGateway;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\RateLimiter;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\ReplayCache;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\SignatureVerifier;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Service\WebhookHandler;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\TestSupport\JsonAssert;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\Responses\CalculateResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebhookHandlerTest extends TestCase
{
    private const SECRET = 'unit-test-secret-padded-to-32+chars';
    private const NOW = 1_700_000_000;

    /**
     * @param list<GuzzleResponse|\Throwable> $engineQueue
     * @param list<GuzzleResponse|\Throwable> $invoiceNinjaQueue
     */
    private function handler(
        array $engineQueue = [],
        array $invoiceNinjaQueue = [],
        int $rateLimit = 100,
    ): WebhookHandler {
        $engineGuzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($engineQueue))]);
        $inGuzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($invoiceNinjaQueue))]);

        $logger = new NullLogger();
        $urlValidator = new UrlValidator(allowPrivateNetworks: true);

        $ost = new OstClient(baseUrl: 'https://ost.example.com', httpClient: $engineGuzzle);
        $engine = new EngineGateway(
            client: $ost,
            urlValidator: $urlValidator,
            engineUrl: 'https://ost.example.com',
            logger: $logger,
        );
        $invoiceNinja = new InvoiceNinjaClient(
            baseUrl: 'https://invoices.example.com',
            apiToken: 'token',
            urlValidator: $urlValidator,
            http: $inGuzzle,
            logger: $logger,
        );
        return new WebhookHandler(
            signature: new SignatureVerifier(
                self::SECRET,
                replayWindowSeconds: 60,
                clock: static fn (): int => self::NOW,
            ),
            replayCache: new ReplayCache(ttlSeconds: 60, clock: static fn (): int => self::NOW),
            rateLimiter: new RateLimiter($rateLimit),
            engine: $engine,
            invoiceNinja: $invoiceNinja,
            logger: $logger,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function signedHeaders(string $body): array
    {
        $signer = new SignatureVerifier(
            self::SECRET,
            replayWindowSeconds: 60,
            clock: static fn (): int => self::NOW,
        );
        return [SignatureVerifier::HEADER_NAME => $signer->sign($body)];
    }

    private static function validBody(): string
    {
        $payload = [
            'id' => 'Aabcd1234',
            'currency_id' => '1',
            'client' => [
                'shipping_country_id' => '840',
                'shipping_postal_code' => '55401-1234',
            ],
            'line_items' => [['cost' => '100.00', 'quantity' => '1']],
        ];
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private static function engineCalculateBody(): string
    {
        return json_encode([
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
    }

    public function testHealthEndpointReturns200(): void
    {
        $h = $this->handler();
        $req = new Request('GET', '/health', [], '', '127.0.0.1');
        $resp = $h->handle($req);
        self::assertSame(200, $resp->status);
        $decoded = JsonAssert::decodeObject($resp->body);
        self::assertSame('opensalestax-invoice-ninja', $decoded['service']);
    }

    public function testUnknownPathReturns404(): void
    {
        $h = $this->handler();
        $req = new Request('GET', '/wat', [], '', '127.0.0.1');
        self::assertSame(404, $h->handle($req)->status);
    }

    public function testHappyPathReturns200WithAppliedTrue(): void
    {
        $h = $this->handler(
            engineQueue: [new GuzzleResponse(200, ['Content-Type' => 'application/json'], self::engineCalculateBody())],
            invoiceNinjaQueue: [new GuzzleResponse(200, [], '{"data":{}}')],
        );
        $body = self::validBody();
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '127.0.0.1');
        $resp = $h->handle($req);
        self::assertSame(200, $resp->status);
        $decoded = JsonAssert::decodeObject($resp->body);
        self::assertTrue($decoded['applied']);
        self::assertSame('Aabcd1234', $decoded['invoice_id']);
        self::assertSame('8.78', $decoded['tax_total']);
        self::assertSame(2, $decoded['jurisdiction_count']);
        self::assertIsString($decoded['disclaimer']);
        self::assertStringContainsString('Verify against your state', $decoded['disclaimer']);
    }

    public function testEngineFailureReturnsAppliedFalseFailSoft(): void
    {
        $h = $this->handler(
            engineQueue: [new GuzzleResponse(500, [], 'oops')],
        );
        $body = self::validBody();
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '127.0.0.1');
        $resp = $h->handle($req);
        self::assertSame(200, $resp->status);
        $decoded = JsonAssert::decodeObject($resp->body);
        self::assertFalse($decoded['applied']);
        self::assertSame('engine_unavailable', $decoded['reason']);
    }

    public function testInvoiceNinjaWritebackFailureReturnsAppliedFalse(): void
    {
        $h = $this->handler(
            engineQueue: [new GuzzleResponse(200, ['Content-Type' => 'application/json'], self::engineCalculateBody())],
            invoiceNinjaQueue: [
                new GuzzleResponse(403, [], 'forbidden'),
            ],
        );
        $body = self::validBody();
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '127.0.0.1');
        $resp = $h->handle($req);
        self::assertSame(200, $resp->status);
        $decoded = JsonAssert::decodeObject($resp->body);
        self::assertFalse($decoded['applied']);
        // Still surfaces calculated tax even though write-back failed.
        self::assertSame('8.78', $decoded['tax_total']);
    }

    public function testMissingSignatureReturns401(): void
    {
        $h = $this->handler();
        $body = self::validBody();
        $req = new Request('POST', '/webhooks/invoice-ninja', [], $body, '127.0.0.1');
        self::assertSame(401, $h->handle($req)->status);
    }

    public function testTamperedBodyReturns401(): void
    {
        $h = $this->handler();
        $signed = self::signedHeaders('{"original":true}');
        $req = new Request(
            'POST',
            '/webhooks/invoice-ninja',
            $signed,
            self::validBody(), // body doesn't match signature
            '127.0.0.1',
        );
        self::assertSame(401, $h->handle($req)->status);
    }

    public function testReplayedRequestReturns409(): void
    {
        $h = $this->handler(
            engineQueue: [new GuzzleResponse(200, ['Content-Type' => 'application/json'], self::engineCalculateBody())],
            invoiceNinjaQueue: [new GuzzleResponse(200, [], '{}')],
        );
        $body = self::validBody();
        $headers = self::signedHeaders($body);
        $req = new Request('POST', '/webhooks/invoice-ninja', $headers, $body, '127.0.0.1');
        self::assertSame(200, $h->handle($req)->status);
        // Re-submit identical request â€” replay cache should reject.
        self::assertSame(409, $h->handle($req)->status);
    }

    public function testNonUsCountryReturns204(): void
    {
        $h = $this->handler();
        $body = json_encode([
            'id' => 'Aabcd1234',
            'currency_id' => '1',
            'client' => ['shipping_country_id' => '124', 'shipping_postal_code' => 'V5K0A1'],
            'line_items' => [['cost' => '100.00', 'quantity' => '1']],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '127.0.0.1');
        self::assertSame(204, $h->handle($req)->status);
    }

    public function testNonUsdCurrencyReturns204(): void
    {
        $h = $this->handler();
        $body = json_encode([
            'id' => 'Aabcd1234',
            'currency_id' => '3', // not USD
            'client' => ['shipping_country_id' => '840', 'shipping_postal_code' => '55401'],
            'line_items' => [['cost' => '100.00', 'quantity' => '1']],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '127.0.0.1');
        self::assertSame(204, $h->handle($req)->status);
    }

    public function testMalformedJsonReturns400(): void
    {
        $h = $this->handler();
        $body = '{not json';
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '127.0.0.1');
        self::assertSame(400, $h->handle($req)->status);
    }

    public function testRateLimitReturns429(): void
    {
        // First request triggers the engine + write-back path (queue both).
        $h = $this->handler(
            engineQueue: [new GuzzleResponse(200, ['Content-Type' => 'application/json'], self::engineCalculateBody())],
            invoiceNinjaQueue: [new GuzzleResponse(200, [], '{}')],
            rateLimit: 1,
        );
        $body = self::validBody();
        $req = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body), $body, '9.9.9.9');
        self::assertSame(200, $h->handle($req)->status); // bucket drains
        // Second request â€” over the limit. Use a different (valid) body so we
        // can't be mistaken for replay; same source IP.
        $body2 = json_encode([
            'id' => 'Bother9876',
            'currency_id' => '1',
            'client' => ['shipping_country_id' => '840', 'shipping_postal_code' => '90210'],
            'line_items' => [['cost' => '200.00', 'quantity' => '1']],
        ], JSON_THROW_ON_ERROR);
        $req2 = new Request('POST', '/webhooks/invoice-ninja', self::signedHeaders($body2), $body2, '9.9.9.9');
        self::assertSame(429, $h->handle($req2)->status);
    }

    public function testWeightedRateZeroSubtotalReturnsZero(): void
    {
        $response = new CalculateResponse(subtotal: '0', taxTotal: '0', lines: [], disclaimer: '');
        self::assertSame(0.0, WebhookHandler::weightedRatePercent($response));
    }
}
