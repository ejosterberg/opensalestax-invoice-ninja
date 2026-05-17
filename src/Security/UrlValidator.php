<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Security;

use InvalidArgumentException;

/**
 * SSRF defense for outbound URLs.
 *
 * Two checks always run:
 *  1. The value parses as a URL with both scheme and host present.
 *  2. The scheme is `http` or `https`.
 *
 * One optional check, gated by the `allowPrivateNetworks` flag:
 *  3. The host resolves to a non-private, non-reserved IP.
 *
 * The optional check defaults OFF (i.e. allow private nets) because the
 * supported deployment pattern is merchant-self-hosted alongside Invoice
 * Ninja and OpenSalesTax engine on the same private network. Operators
 * exposing the sidecar to untrusted webhook senders should set
 * SIDECAR_ALLOW_PRIVATE_NETWORKS=0.
 */
final class UrlValidator
{
    /** @var callable(string): ?string */
    private $hostResolver;

    /**
     * @param callable(string): ?string|null $hostResolver Returns IP, or null if
     *     unresolvable. Default uses gethostbyname; tests pass a deterministic mock.
     */
    public function __construct(
        private readonly bool $allowPrivateNetworks,
        ?callable $hostResolver = null,
    ) {
        $this->hostResolver = $hostResolver ?? static function (string $host): ?string {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return $host;
            }
            $resolved = gethostbyname($host);
            return $resolved === $host ? null : $resolved;
        };
    }

    /**
     * Validate a URL is safe to dial. Returns the resolved IP when not in
     * allow-private mode (so the caller can pin it to defeat DNS rebinding),
     * or null when private networks are allowed.
     *
     * @throws InvalidArgumentException on any rejection.
     */
    public function validate(string $url): ?string
    {
        if ($url === '') {
            throw new InvalidArgumentException('URL must not be empty.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(
                'URL must be fully-qualified (e.g. https://host.example).',
            );
        }
        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL scheme must be http or https.');
        }
        if ($this->allowPrivateNetworks) {
            return null;
        }
        return $this->resolveAndCheckPublic($parts['host']);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resolveAndCheckPublic(string $host): string
    {
        $ip = ($this->hostResolver)($host);
        if ($ip === null) {
            throw new InvalidArgumentException('URL host could not be resolved.');
        }
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($isPublic === false) {
            throw new InvalidArgumentException(
                'URL must resolve to a public IP when SIDECAR_ALLOW_PRIVATE_NETWORKS=0.',
            );
        }
        return $ip;
    }
}
