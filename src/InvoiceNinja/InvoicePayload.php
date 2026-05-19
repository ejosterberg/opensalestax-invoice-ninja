<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja;

use InvalidArgumentException;

/**
 * Typed view of an Invoice Ninja webhook payload (just the parts we need).
 *
 * Invoice Ninja v5 ships invoice payloads with the shape:
 *
 *   {
 *     "id": "Aabcd123",
 *     "client_id": "Xyz9876",
 *     "currency_id": "1",         // 1 = USD in Invoice Ninja seeds
 *     "client": {
 *       "id": "Xyz9876",
 *       "shipping_country_id": "840",  // ISO numeric, 840 = US
 *       "shipping_postal_code": "55401-1234",
 *       "country_id": "840",
 *       "postal_code": "55401"
 *     },
 *     "line_items": [
 *       {"product_key": "...", "notes": "...", "cost": "100.00",
 *        "quantity": "1", "tax_name1": "", "tax_rate1": 0, "type_id": "1"}
 *     ]
 *   }
 *
 * We extract only what /v1/calculate needs: the destination ZIP5 and the
 * per-line non-negative decimal amounts. The category is hard-coded to
 * "general" in v0.1; v0.2 maps Invoice Ninja product custom-fields to
 * engine categories.
 *
 * Currency: USD only. Invoice Ninja's currency_id 1 = USD per default seed
 * (admins can re-seed, so we double-check by currency code if the payload
 * embeds a client.currency.code field).
 *
 * Country: US only. ISO numeric 840 = United States.
 */
final class InvoicePayload
{
    /**
     * @param InvoiceLine[] $lines
     */
    private function __construct(
        public readonly string $invoiceId,
        public readonly string $zip5,
        public readonly ?string $zip4,
        public readonly string $currencyCode,
        public readonly string $countryCode,
        public readonly array $lines,
        /**
         * Upper-case 2-letter US state code (e.g. "MN"), or null if the
         * payload didn't include a resolvable state. Used by the per-state
         * nexus filter (CP-3, v0.3.0). Invoice Ninja v5 stores the state
         * on `client.shipping_state` (or `client.state` as fallback) as a
         * 2-letter code; we accept that directly.
         */
        public readonly ?string $stateCode = null,
    ) {
    }

    /**
     * @param array<mixed> $data Decoded JSON body from Invoice Ninja's webhook.
     * @throws PayloadException on a structurally invalid or unprocessable payload.
     */
    public static function fromArray(array $data): self
    {
        $invoiceId = self::stringField($data, 'id');
        if ($invoiceId === '') {
            throw new PayloadException('payload missing invoice id');
        }

        $client = $data['client'] ?? null;
        if (!is_array($client)) {
            throw new PayloadException('payload missing client object');
        }

        $countryCode = self::resolveCountryCode($client);
        $currencyCode = self::resolveCurrencyCode($data, $client);

        [$zip5, $zip4] = self::resolveZip($client);

        $linesRaw = $data['line_items'] ?? null;
        if (!is_array($linesRaw)) {
            throw new PayloadException('payload missing line_items array');
        }
        $lines = [];
        foreach ($linesRaw as $idx => $raw) {
            if (!is_array($raw)) {
                throw new PayloadException("line_items[{$idx}] is not an object");
            }
            $lines[] = InvoiceLine::fromArray($raw, $idx);
        }
        if ($lines === []) {
            throw new PayloadException('payload has no line items');
        }

        $stateCode = self::resolveStateCode($client);

        return new self($invoiceId, $zip5, $zip4, $currencyCode, $countryCode, $lines, $stateCode);
    }

    /**
     * Best-effort extraction of a 2-letter US state code from the client
     * object. Invoice Ninja's standard webhook ships `shipping_state` and
     * `state` as 2-letter strings. Returns null when neither field yields
     * a 2-letter value. The nexus filter treats null as "unresolvable"
     * (fail-closed) when the filter is active.
     *
     * @param array<mixed> $client
     */
    private static function resolveStateCode(array $client): ?string
    {
        $candidates = [
            $client['shipping_state'] ?? null,
            $client['state'] ?? null,
        ];
        foreach ($candidates as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $upper = strtoupper(trim($raw));
            if (preg_match('/^[A-Z]{2}$/', $upper) === 1) {
                return $upper;
            }
        }
        return null;
    }

    /**
     * @param array<mixed> $client
     * @return array{0: string, 1: ?string}
     */
    private static function resolveZip(array $client): array
    {
        $candidates = [
            $client['shipping_postal_code'] ?? null,
            $client['postal_code'] ?? null,
        ];
        foreach ($candidates as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            // Strip everything but digits, then split into zip5 / zip4.
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) < 5) {
                continue;
            }
            $zip5 = substr($digits, 0, 5);
            $zip4 = strlen($digits) >= 9 ? substr($digits, 5, 4) : null;
            return [$zip5, $zip4];
        }
        throw new PayloadException('payload has no usable US postal code');
    }

    /**
     * @param array<mixed> $client
     */
    private static function resolveCountryCode(array $client): string
    {
        $candidates = [
            $client['shipping_country_id'] ?? null,
            $client['country_id'] ?? null,
        ];
        $cc = null;
        foreach ($candidates as $raw) {
            $s = self::scalarToString($raw);
            if ($s === '') {
                continue;
            }
            // Invoice Ninja uses ISO-3166 numeric codes ("840" for US).
            if ($s === '840') {
                return 'US';
            }
            $cc = $s;
        }
        if ($cc === null) {
            throw new PayloadException('payload has no client country');
        }
        throw new PayloadException("client country '{$cc}' is not US (expected ISO numeric 840)");
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $client
     */
    private static function resolveCurrencyCode(array $data, array $client): string
    {
        $expanded = self::extractExpandedCurrency($data, $client);
        if ($expanded !== null) {
            return $expanded;
        }
        // Invoice Ninja v5's standard `invoice.created` webhook does NOT
        // emit `currency_id` at the top level. The currency is buried in
        // `client.settings.currency_id`. Probe each known location.
        $cid = self::scalarToString(
            $data['currency_id']
            ?? $client['currency_id']
            ?? (is_array($client['settings'] ?? null) ? ($client['settings']['currency_id'] ?? null) : null)
        );
        if ($cid === '1') {
            return 'USD';
        }
        if ($cid === '') {
            throw new PayloadException('payload has no currency identifier');
        }
        throw new PayloadException("invoice currency_id '{$cid}' is not the USD seed (1)");
    }

    /**
     * Looks for an expanded `currency.code` object in either the invoice or
     * the client bag. Returns 'USD' on USD, throws on non-USD, returns null
     * when no expanded currency code is present (caller falls back to numeric).
     *
     * @param array<mixed> $data
     * @param array<mixed> $client
     */
    private static function extractExpandedCurrency(array $data, array $client): ?string
    {
        foreach ([$data, $client] as $bag) {
            $currency = $bag['currency'] ?? null;
            if (!is_array($currency)) {
                continue;
            }
            $code = $currency['code'] ?? null;
            if (!is_string($code) || $code === '') {
                continue;
            }
            if (strtoupper($code) !== 'USD') {
                throw new PayloadException("invoice currency '{$code}' is not USD");
            }
            return 'USD';
        }
        return null;
    }

    /**
     * Coerce a scalar field value to a string. Anything that isn't int or
     * string becomes the empty string (which callers treat as "missing").
     */
    private static function scalarToString(mixed $raw): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }
        return is_string($raw) ? $raw : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $raw = $data[$key] ?? null;
        if (is_string($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return (string) $raw;
        }
        return '';
    }
}
