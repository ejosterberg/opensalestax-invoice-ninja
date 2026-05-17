<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Security;

/**
 * Verifies HMAC-SHA256 signatures on inbound webhook requests.
 *
 * Wire format (matches the Stripe / GitHub convention so it is easy to
 * generate from Invoice Ninja's webhook headers or a small upstream
 * shim):
 *
 *     X-Sidecar-Signature: t=<unix-seconds>,v1=<hex-digest>
 *
 * The signed payload is the literal string `<t>.<raw-body>` keyed
 * with the shared secret. Verification uses hash_equals to keep
 * comparison constant-time.
 *
 * Returns the timestamp on success so the caller can hand it to
 * the replay cache.
 *
 * @phpstan-type ParsedHeader array{t: int, v1: string}
 */
final class SignatureVerifier
{
    public const HEADER_NAME = 'X-Sidecar-Signature';

    public function __construct(
        private readonly string $secret,
        private readonly int $replayWindowSeconds,
        /** @var callable(): int */
        private $clock = null,
    ) {
        if ($this->clock === null) {
            $this->clock = static fn (): int => time();
        }
    }

    /**
     * @throws SignatureException
     */
    public function verify(string $rawBody, ?string $headerValue): int
    {
        if ($headerValue === null || $headerValue === '') {
            throw new SignatureException('signature header missing');
        }
        $parsed = self::parseHeader($headerValue);
        $now = ($this->clock)();
        $age = $now - $parsed['t'];
        if ($age > $this->replayWindowSeconds || $age < -$this->replayWindowSeconds) {
            throw new SignatureException(
                sprintf('signature timestamp outside replay window (age=%ds)', $age),
            );
        }
        $expected = hash_hmac('sha256', $parsed['t'] . '.' . $rawBody, $this->secret);
        if (!hash_equals($expected, $parsed['v1'])) {
            throw new SignatureException('signature mismatch');
        }
        return $parsed['t'];
    }

    /**
     * Produce a signed header value for use by integration test harnesses.
     * Not used at runtime by the sidecar (we only verify, never sign).
     */
    public function sign(string $rawBody, ?int $atTimestamp = null): string
    {
        $t = $atTimestamp ?? ($this->clock)();
        $digest = hash_hmac('sha256', $t . '.' . $rawBody, $this->secret);
        return sprintf('t=%d,v1=%s', $t, $digest);
    }

    /**
     * @return ParsedHeader
     * @throws SignatureException
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
            throw new SignatureException('signature header malformed');
        }
        return ['t' => $t, 'v1' => $v1];
    }
}
