<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing;

/**
 * Produces HMAC-SHA256 signed headers in the wire format expected by the
 * OpenSalesTax sidecar:
 *
 *     X-Sidecar-Signature: t=<unix-seconds>,v1=<hex-digest>
 *
 * The signed payload is the literal string `<t>.<raw-body>` keyed with
 * the shared secret. This algorithm MUST stay byte-for-byte identical
 * with the sidecar's `SignatureVerifier::sign()` in this same repo. The
 * cross-package roundtrip test guarantees that contract.
 */
final class Signer
{
    /**
     * @var callable(): int
     */
    private $clock;

    /**
     * @param callable(): int|null $clock optional clock for tests; defaults to time()
     */
    public function __construct(
        private readonly string $secret,
        ?callable $clock = null,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('signing secret must not be empty');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Produce a header value of the form `t=<unix-seconds>,v1=<hex-digest>`.
     *
     * @param int|null $atTimestamp override for deterministic tests
     */
    public function sign(string $rawBody, ?int $atTimestamp = null): string
    {
        $t = $atTimestamp ?? ($this->clock)();
        $digest = hash_hmac('sha256', $t . '.' . $rawBody, $this->secret);
        return sprintf('t=%d,v1=%s', $t, $digest);
    }
}
