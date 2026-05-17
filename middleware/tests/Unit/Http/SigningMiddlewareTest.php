<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Http;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Http\SigningMiddleware;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SigningMiddlewareTest extends TestCase
{
    private const SECRET = 'middleware-test-secret-32-charsXX';
    private const NOW = 1_700_000_000;

    private function makeMiddleware(
        bool $enabled = true,
        string $prefix = 'http://sidecar.local/webhooks/',
        string $header = 'X-Sidecar-Signature',
    ): SigningMiddleware {
        $clock = static fn (): int => self::NOW;
        return new SigningMiddleware(
            signer: new Signer(self::SECRET, $clock),
            headerName: $header,
            sidecarUrlPrefix: $prefix,
            enabled: $enabled,
        );
    }

    public function testSignsPostRequestToMatchingUrl(): void
    {
        $mw = $this->makeMiddleware();
        $req = new Request(
            'POST',
            'http://sidecar.local/webhooks/invoice-ninja',
            ['Content-Type' => 'application/json'],
            '{"id":"abc"}',
        );
        $signed = $mw->maybeSign($req);
        self::assertTrue($signed->hasHeader('X-Sidecar-Signature'));
        self::assertMatchesRegularExpression(
            '/^t=1700000000,v1=[0-9a-f]{64}$/',
            $signed->getHeaderLine('X-Sidecar-Signature'),
        );
    }

    public function testDoesNotSignWhenDisabled(): void
    {
        $mw = $this->makeMiddleware(enabled: false);
        $req = new Request('POST', 'http://sidecar.local/webhooks/invoice-ninja', [], '{"x":1}');
        $signed = $mw->maybeSign($req);
        self::assertFalse($signed->hasHeader('X-Sidecar-Signature'));
    }

    public function testDoesNotSignNonMatchingUrl(): void
    {
        $mw = $this->makeMiddleware();
        $req = new Request('POST', 'https://api.stripe.com/v1/charges', [], 'payload');
        $signed = $mw->maybeSign($req);
        self::assertFalse($signed->hasHeader('X-Sidecar-Signature'));
    }

    public function testDoesNotSignGetRequest(): void
    {
        $mw = $this->makeMiddleware();
        $req = new Request('GET', 'http://sidecar.local/webhooks/invoice-ninja');
        $signed = $mw->maybeSign($req);
        self::assertFalse($signed->hasHeader('X-Sidecar-Signature'));
    }

    public function testEmptyPrefixDoesNotSignAnything(): void
    {
        $mw = $this->makeMiddleware(prefix: '');
        $req = new Request('POST', 'http://sidecar.local/webhooks/invoice-ninja', [], 'x');
        $signed = $mw->maybeSign($req);
        self::assertFalse($signed->hasHeader('X-Sidecar-Signature'));
    }

    public function testCustomHeaderNameIsUsed(): void
    {
        $mw = $this->makeMiddleware(header: 'X-Custom-Header');
        $req = new Request('POST', 'http://sidecar.local/webhooks/invoice-ninja', [], 'x');
        $signed = $mw->maybeSign($req);
        self::assertTrue($signed->hasHeader('X-Custom-Header'));
        self::assertFalse($signed->hasHeader('X-Sidecar-Signature'));
    }

    public function testBodyStreamIsRewoundAfterSigning(): void
    {
        $mw = $this->makeMiddleware();
        $req = new Request('POST', 'http://sidecar.local/webhooks/invoice-ninja', [], '{"id":"abc"}');
        $signed = $mw->maybeSign($req);
        // Downstream client must still be able to read the body in full.
        self::assertSame('{"id":"abc"}', (string) $signed->getBody());
    }

    public function testInvokeReturnsAHandlerFactory(): void
    {
        $mw = $this->makeMiddleware();
        $factory = $mw();
        self::assertIsCallable($factory);

        $captured = null;
        $innerHandler = function (RequestInterface $req, array $opts) use (&$captured) {
            $captured = $req;
            return null;
        };
        $stack = $factory($innerHandler);
        self::assertIsCallable($stack);

        $req = new Request('POST', 'http://sidecar.local/webhooks/invoice-ninja', [], '{"id":"abc"}');
        $stack($req, []);
        self::assertNotNull($captured);
        self::assertTrue($captured->hasHeader('X-Sidecar-Signature'));
    }

    public function testSignsLowercaseMethod(): void
    {
        $mw = $this->makeMiddleware();
        $req = new Request('post', 'http://sidecar.local/webhooks/invoice-ninja', [], 'x');
        $signed = $mw->maybeSign($req);
        self::assertTrue($signed->hasHeader('X-Sidecar-Signature'));
    }

    public function testSignatureContainsHmacOfTimestampDotBody(): void
    {
        $mw = $this->makeMiddleware();
        $body = '{"event":"invoice.created"}';
        $req = new Request('POST', 'http://sidecar.local/webhooks/invoice-ninja', [], $body);
        $signed = $mw->maybeSign($req);
        $expected = hash_hmac('sha256', self::NOW . '.' . $body, self::SECRET);
        self::assertStringContainsString(
            'v1=' . $expected,
            $signed->getHeaderLine('X-Sidecar-Signature'),
        );
    }

    public function testReplayWindowIsRespectableByVerifier(): void
    {
        // The signature carries a t= timestamp the sidecar checks against
        // its 300s default replay window. We don't enforce that window
        // here (signing side), but we do guarantee that the timestamp
        // attached is the current clock value â€” never stale.
        $clock = static fn (): int => 1_800_000_000;
        $mw = new SigningMiddleware(
            signer: new Signer(self::SECRET, $clock),
            headerName: 'X-Sidecar-Signature',
            sidecarUrlPrefix: 'http://sidecar.local/',
            enabled: true,
        );
        $req = new Request('POST', 'http://sidecar.local/webhooks/x', [], '{}');
        $signed = $mw->maybeSign($req);
        self::assertStringStartsWith(
            't=1800000000,',
            $signed->getHeaderLine('X-Sidecar-Signature'),
        );
    }
}
