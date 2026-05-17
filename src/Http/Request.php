<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Http;

/**
 * Minimal request envelope â€” kept framework-agnostic so the sidecar can run
 * under any PHP entry-point (built-in dev server, php-fpm + nginx, or a
 * Symfony HttpFoundation adapter in v0.2).
 *
 * @phpstan-type Headers array<string, string>
 */
final class Request
{
    /**
     * @param Headers $headers Case-insensitive lookups via header().
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $sourceIp,
    ) {
    }

    public function header(string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === $needle) {
                return $v;
            }
        }
        return null;
    }
}
