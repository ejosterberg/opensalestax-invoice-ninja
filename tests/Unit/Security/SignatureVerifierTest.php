<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Security;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\SignatureException;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'this-is-a-test-secret-32-chars+';

    public function testRoundTripVerifiesSuccessfully(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $v = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $clock);
        $body = '{"id":"abc"}';
        $header = $v->sign($body);
        self::assertSame(1_700_000_000, $v->verify($body, $header));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $v = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $clock);
        $header = $v->sign('{"id":"abc"}');
        $this->expectException(SignatureException::class);
        $v->verify('{"id":"xyz"}', $header);
    }

    public function testWrongSecretRejected(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $clock);
        $header = $signer->sign('{"id":"abc"}');

        $verifier = new SignatureVerifier(
            'different-secret-of-equal-length!!',
            replayWindowSeconds: 60,
            clock: $clock,
        );
        $this->expectException(SignatureException::class);
        $verifier->verify('{"id":"abc"}', $header);
    }

    public function testMissingHeaderIsRejected(): void
    {
        $v = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60);
        $this->expectException(SignatureException::class);
        $v->verify('{}', null);
    }

    public function testEmptyHeaderIsRejected(): void
    {
        $v = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60);
        $this->expectException(SignatureException::class);
        $v->verify('{}', '');
    }

    public function testMalformedHeaderIsRejected(): void
    {
        $v = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60);
        $this->expectException(SignatureException::class);
        $v->verify('{}', 'definitely-not-a-signature');
    }

    public function testStaleTimestampRejected(): void
    {
        $signClock = static fn (): int => 1_700_000_000;
        $verifyClock = static fn (): int => 1_700_000_120;
        $signer = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $signClock);
        $header = $signer->sign('{}');

        $verifier = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $verifyClock);
        $this->expectException(SignatureException::class);
        $verifier->verify('{}', $header);
    }

    public function testFutureTimestampRejected(): void
    {
        $signClock = static fn (): int => 1_700_000_500;
        $verifyClock = static fn (): int => 1_700_000_000;
        $signer = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $signClock);
        $header = $signer->sign('{}');

        $verifier = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60, clock: $verifyClock);
        $this->expectException(SignatureException::class);
        $verifier->verify('{}', $header);
    }

    public function testV1HexDigestLengthIsEnforced(): void
    {
        $v = new SignatureVerifier(self::SECRET, replayWindowSeconds: 60);
        // 63-char hex (one short of 64) â€” malformed
        $this->expectException(SignatureException::class);
        $v->verify('{}', 't=1700000000,v1=' . str_repeat('a', 63));
    }
}
