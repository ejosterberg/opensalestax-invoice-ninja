<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja;

/**
 * Typed view of a single Invoice Ninja invoice line item.
 *
 * Invoice Ninja v5 stores monetary amounts as decimal strings on the wire
 * (precision-safe). We propagate strings to the OST SDK rather than parse
 * to floats; the engine quantizes per-jurisdiction in fixed point and
 * floats would round at the boundary.
 *
 * Line subtotal = cost * quantity. We compute that as a decimal string
 * via bcmath when available, falling back to a small ad-hoc decimal-multiply
 * helper. This is what we send the engine as the line `amount`.
 */
final class InvoiceLine
{
    private function __construct(
        public readonly string $cost,
        public readonly string $quantity,
        public readonly string $subtotal,
        public readonly string $productKey,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, int $idx): self
    {
        $cost = self::decString($data, 'cost', $idx);
        $quantity = self::decString($data, 'quantity', $idx);
        if (self::startsWithMinus($cost) || self::startsWithMinus($quantity)) {
            throw new PayloadException("line_items[{$idx}] cost/quantity must be non-negative");
        }
        $subtotal = self::multiplyDecimalStrings($cost, $quantity);
        $productKey = isset($data['product_key']) && is_string($data['product_key'])
            ? $data['product_key']
            : '';
        return new self($cost, $quantity, $subtotal, $productKey);
    }

    /**
     * @param array<mixed> $data
     */
    private static function decString(array $data, string $key, int $idx): string
    {
        $raw = $data[$key] ?? null;
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw) || $raw === '') {
            throw new PayloadException("line_items[{$idx}] missing decimal field '{$key}'");
        }
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $raw) !== 1) {
            throw new PayloadException("line_items[{$idx}].{$key} is not a decimal: '{$raw}'");
        }
        // Strip leading '+'.
        if (str_starts_with($raw, '+')) {
            $raw = substr($raw, 1);
        }
        return $raw;
    }

    private static function startsWithMinus(string $s): bool
    {
        return str_starts_with($s, '-');
    }

    /**
     * Multiply two decimal strings. Uses bcmath if loaded; otherwise a small
     * integer-shift helper that handles the precision we care about (cents).
     */
    private static function multiplyDecimalStrings(string $a, string $b): string
    {
        if (extension_loaded('bcmath')) {
            return rtrim(rtrim(bcmul($a, $b, 6), '0'), '.') ?: '0';
        }
        // Fallback: shift each to integer by stripping the decimal, multiply,
        // then re-insert. Loses precision past ~9 fractional digits combined.
        [$aInt, $aScale] = self::splitDecimal($a);
        [$bInt, $bScale] = self::splitDecimal($b);
        $product = (int) $aInt * (int) $bInt;
        $totalScale = $aScale + $bScale;
        if ($totalScale === 0) {
            return (string) $product;
        }
        $padded = str_pad((string) $product, $totalScale + 1, '0', STR_PAD_LEFT);
        $intPart = substr($padded, 0, -$totalScale);
        $fracPart = rtrim(substr($padded, -$totalScale), '0');
        return $fracPart === '' ? $intPart : ($intPart . '.' . $fracPart);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function splitDecimal(string $s): array
    {
        $pos = strpos($s, '.');
        if ($pos === false) {
            return [$s, 0];
        }
        $intPart = substr($s, 0, $pos);
        $fracPart = substr($s, $pos + 1);
        return [$intPart . $fracPart, strlen($fracPart)];
    }
}
