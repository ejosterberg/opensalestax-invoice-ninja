<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Signing;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    private const SECRET = 'this-is-a-test-secret-32-chars+';

    public function testSignProducesStripeStyleHeader(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $header = $signer->sign('{"id":"abc"}');
        self::assertMatchesRegularExpression(
            '/^t=1700000000,v1=[0-9a-f]{64}$/',
            $header,
        );
    }

    public function testSignIsDeterministicForFixedClock(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $a = new Signer(self::SECRET, $clock);
        $b = new Signer(self::SECRET, $clock);
        self::assertSame($a->sign('payload'), $b->sign('payload'));
    }

    public function testDifferentBodiesProduceDifferentDigests(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        self::assertNotSame(
            $signer->sign('{"a":1}'),
            $signer->sign('{"a":2}'),
        );
    }

    public function testDifferentSecretsProduceDifferentDigests(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $a = new Signer(self::SECRET, $clock);
        $b = new Signer('another-different-secret-32chars!!', $clock);
        self::assertNotSame($a->sign('payload'), $b->sign('payload'));
    }

    public function testAtTimestampOverrideIsHonored(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $header = $signer->sign('payload', atTimestamp: 1_234_567_890);
        self::assertStringStartsWith('t=1234567890,v1=', $header);
    }

    public function testEmptySecretRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Signer('');
    }

    public function testDefaultClockUsesSystemTime(): void
    {
        $before = time();
        $signer = new Signer(self::SECRET);
        $header = $signer->sign('payload');
        $after = time();

        $matches = [];
        $result = preg_match('/^t=(\d+),v1=[0-9a-f]{64}$/', $header, $matches);
        self::assertSame(1, $result);
        $captured = $matches[1] ?? '';
        $t = (int) $captured;
        self::assertGreaterThanOrEqual($before, $t);
        self::assertLessThanOrEqual($after, $t);
    }

    public function testDigestMatchesManualHmacSha256(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $body = '{"event_type":"invoice.created","data":{"id":"abc"}}';
        $header = $signer->sign($body);
        $expected = hash_hmac('sha256', '1700000000.' . $body, self::SECRET);
        self::assertStringContainsString('v1=' . $expected, $header);
    }

    public function testEmptyBodyStillSigns(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $header = $signer->sign('');
        self::assertMatchesRegularExpression(
            '/^t=1700000000,v1=[0-9a-f]{64}$/',
            $header,
        );
    }

    public function testBinaryBodySigns(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $body = "\x00\x01\x02\xff\xfe";
        $header = $signer->sign($body);
        $expected = hash_hmac('sha256', '1700000000.' . $body, self::SECRET);
        self::assertStringContainsString('v1=' . $expected, $header);
    }

    /**
     * The signature contract is shared with the sidecar's
     * SignatureVerifier::sign(). This test pins the algorithm to a known
     * fixture so any drift between the two packages is caught.
     */
    public function testKnownVector(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer('test-secret', $clock);
        $body = 'hello';
        $expectedDigest = hash_hmac('sha256', '1700000000.hello', 'test-secret');
        self::assertSame(
            't=1700000000,v1=' . $expectedDigest,
            $signer->sign($body),
        );
    }
}
