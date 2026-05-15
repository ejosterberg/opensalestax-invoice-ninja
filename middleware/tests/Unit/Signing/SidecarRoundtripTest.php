<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Signing;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use PHPUnit\Framework\TestCase;

/**
 * Cross-package contract test.
 *
 * The shim's Signer must produce header values that the sidecar's
 * SignatureVerifier accepts byte-for-byte. We mirror the sidecar's
 * verification logic locally (since the shim cannot take a hard
 * dependency on the sidecar's source tree — they are deployed to
 * different machines) and roundtrip a variety of payloads.
 *
 * If this test ever fails, the wire format has drifted and the shim
 * must NOT be released until parity is restored.
 */
final class SidecarRoundtripTest extends TestCase
{
    private const SECRET = 'roundtrip-shared-secret-32-chars';
    private const NOW = 1_700_000_000;

    /**
     * @return array<string, array{0: string}>
     */
    public static function payloadProvider(): array
    {
        return [
            'empty' => [''],
            'small json' => ['{"id":"abc"}'],
            'invoice.created sample' => [
                '{"event_type":"invoice.created","data":{"id":"INV-0001","total":12345}}',
            ],
            'unicode' => ['{"name":"Café Olé","sku":"☃"}'],
            'newlines and tabs' => ["line one\nline two\tindented"],
            'large body' => [str_repeat('A', 8192)],
        ];
    }

    /**
     * @dataProvider payloadProvider
     */
    public function testRoundtripVerifies(string $body): void
    {
        $clock = static fn (): int => self::NOW;
        $signer = new Signer(self::SECRET, $clock);
        $header = $signer->sign($body);

        $parsed = self::parseHeader($header);
        $expected = hash_hmac('sha256', $parsed['t'] . '.' . $body, self::SECRET);
        self::assertSame($expected, $parsed['v1']);
        self::assertSame(self::NOW, $parsed['t']);
    }

    /**
     * @dataProvider payloadProvider
     */
    public function testRoundtripFailsWithTamperedBody(string $body): void
    {
        $clock = static fn (): int => self::NOW;
        $signer = new Signer(self::SECRET, $clock);
        $header = $signer->sign($body);

        $parsed = self::parseHeader($header);
        $expected = hash_hmac('sha256', $parsed['t'] . '.' . $body . 'TAMPER', self::SECRET);
        self::assertNotSame($expected, $parsed['v1']);
    }

    /**
     * Mirrors SignatureVerifier::parseHeader() to keep this test
     * self-contained.
     *
     * @return array{t: int, v1: string}
     */
    private static function parseHeader(string $headerValue): array
    {
        $t = null;
        $v1 = null;
        foreach (explode(',', $headerValue) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$k, $val] = $kv;
            if ($k === 't' && ctype_digit($val)) {
                $t = (int) $val;
            } elseif ($k === 'v1' && preg_match('/^[0-9a-f]{64}$/', $val) === 1) {
                $v1 = $val;
            }
        }
        if ($t === null || $v1 === null) {
            throw new \RuntimeException('header malformed');
        }
        return ['t' => $t, 'v1' => $v1];
    }
}
