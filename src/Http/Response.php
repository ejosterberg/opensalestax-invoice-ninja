<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Http;

/**
 * Minimal response envelope. The disclaimer constant satisfies the
 * project constitution Â§10 â€” every response surfacing tax must repeat
 * it.
 */
final class Response
{
    public const DISCLAIMER = 'Tax calculations are provided as-is for convenience. '
        . 'The merchant is solely responsible for tax-collection accuracy and remittance '
        . 'to the appropriate jurisdictions. Verify against your state Department of '
        . 'Revenue before remitting.';

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(int $status, array $body): self
    {
        return new self(
            $status,
            (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json'],
        );
    }

    public static function noContent(int $status = 204): self
    {
        return new self($status, '', []);
    }

    public static function plain(int $status, string $reason): self
    {
        return new self($status, $reason, ['Content-Type' => 'text/plain']);
    }
}
