<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Signing;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test that exercises the *actual* sidecar `SignatureVerifier`
 * against headers produced by the shim's `Signer`. This is the only
 * test in the suite that requires the sidecar's source files to be
 * present in the parent directory; it is skipped if they aren't (so
 * the package can still be installed and tested standalone via
 * `composer create-project` in a downstream project).
 */
final class SidecarVerifyParityTest extends TestCase
{
    private const SECRET = 'parity-test-secret-32-charsXXXXX';
    private const REPLAY_WINDOW = 60;

    protected function setUp(): void
    {
        $verifierPath = __DIR__ . '/../../../../src/Security/SignatureVerifier.php';
        $exceptionPath = __DIR__ . '/../../../../src/Security/SignatureException.php';
        if (!file_exists($verifierPath) || !file_exists($exceptionPath)) {
            $this->markTestSkipped('Sidecar source not present alongside shim.');
        }
        require_once $exceptionPath;
        require_once $verifierPath;
    }

    public function testShimSignedHeaderVerifiesOnSidecar(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $body = '{"event_type":"invoice.created","data":{"id":"INV-0001"}}';
        $header = $signer->sign($body);

        $verifierClass = '\\EJOsterberg\\OpenSalesTax\\InvoiceNinja\\Security\\SignatureVerifier';
        self::assertTrue(class_exists($verifierClass));

        /** @var object $verifier */
        $verifier = new $verifierClass(self::SECRET, self::REPLAY_WINDOW, $clock);
        /** @var int $verifiedAt */
        $verifiedAt = $verifier->verify($body, $header);
        self::assertSame(1_700_000_000, $verifiedAt);
    }

    public function testSidecarRejectsTamperedBody(): void
    {
        $clock = static fn (): int => 1_700_000_000;
        $signer = new Signer(self::SECRET, $clock);
        $header = $signer->sign('{"id":"clean"}');

        $verifierClass = '\\EJOsterberg\\OpenSalesTax\\InvoiceNinja\\Security\\SignatureVerifier';
        $exceptionClass = '\\EJOsterberg\\OpenSalesTax\\InvoiceNinja\\Security\\SignatureException';
        /** @var object $verifier */
        $verifier = new $verifierClass(self::SECRET, self::REPLAY_WINDOW, $clock);

        $this->expectException($exceptionClass);
        $verifier->verify('{"id":"tampered"}', $header);
    }

    public function testSidecarRejectsStaleTimestamp(): void
    {
        $signerClock = static fn (): int => 1_700_000_000;
        $verifierClock = static fn (): int => 1_700_000_000 + self::REPLAY_WINDOW + 1;
        $signer = new Signer(self::SECRET, $signerClock);
        $header = $signer->sign('{"id":"x"}');

        $verifierClass = '\\EJOsterberg\\OpenSalesTax\\InvoiceNinja\\Security\\SignatureVerifier';
        $exceptionClass = '\\EJOsterberg\\OpenSalesTax\\InvoiceNinja\\Security\\SignatureException';
        /** @var object $verifier */
        $verifier = new $verifierClass(self::SECRET, self::REPLAY_WINDOW, $verifierClock);

        $this->expectException($exceptionClass);
        $verifier->verify('{"id":"x"}', $header);
    }
}
