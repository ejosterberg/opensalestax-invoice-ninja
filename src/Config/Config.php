<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Config;

/**
 * Immutable runtime configuration for the sidecar.
 *
 * Loaded from environment variables exactly once at boot. Secrets
 * (engine API key, Invoice Ninja API token, webhook signing secret)
 * are never logged and never serialized via __toString().
 *
 * Required env vars:
 * - OST_ENGINE_URL                  base URL of the OpenSalesTax engine
 * - IN_API_URL                      base URL of the Invoice Ninja instance
 *                                   (https://invoices.example.com)
 * - IN_API_TOKEN                    Invoice Ninja API token (X-Api-Token)
 * - IN_WEBHOOK_SIGNING_SECRET       HMAC secret shared with Invoice Ninja
 *
 * Optional:
 * - OST_API_KEY                     Bearer token for the OST engine, if any
 * - OST_TIMEOUT_SECONDS             default 10
 * - SIDECAR_ALLOW_PRIVATE_NETWORKS  "1" to permit RFC1918 OST/IN URLs
 *                                   (default ON â€” supported deployment
 *                                   pattern is same-VM self-hosting)
 * - SIDECAR_REPLAY_WINDOW_SECONDS   default 300; max age of webhook
 *                                   timestamp before we reject as replay
 * - SIDECAR_TLS_VERIFY              "0" disables TLS verification
 *                                   (default ON; only off for local dev)
 * - SIDECAR_RATE_LIMIT_PER_MINUTE   default 120; in-memory token bucket
 * - OST_NEXUS_STATES                Comma-separated US 2-letter state
 *                                   codes (e.g. "MN,WI,IA") — when set,
 *                                   sidecar short-circuits the engine
 *                                   call for invoices whose ship-to /
 *                                   billing state is not in the list.
 *                                   Empty / unset = filter disabled
 *                                   (engine called for every US/USD
 *                                   invoice — pre-v0.3 behavior).
 */
final class Config
{
    /** @var array<string, string> */
    private array $env;

    /**
     * @param array<string, string>|null $env defaults to the process environment.
     */
    public function __construct(?array $env = null)
    {
        $this->env = $env ?? self::loadFromProcess();
    }

    public function engineUrl(): string
    {
        return $this->required('OST_ENGINE_URL');
    }

    public function engineApiKey(): ?string
    {
        $v = $this->optional('OST_API_KEY');
        return ($v === null || $v === '') ? null : $v;
    }

    public function engineTimeoutSeconds(): float
    {
        $v = $this->optional('OST_TIMEOUT_SECONDS');
        if ($v === null || $v === '') {
            return 10.0;
        }
        if (!is_numeric($v)) {
            throw new ConfigException('OST_TIMEOUT_SECONDS must be numeric');
        }
        $f = (float) $v;
        if ($f <= 0.0 || $f > 60.0) {
            throw new ConfigException('OST_TIMEOUT_SECONDS must be in (0, 60]');
        }
        return $f;
    }

    public function invoiceNinjaUrl(): string
    {
        return $this->required('IN_API_URL');
    }

    public function invoiceNinjaToken(): string
    {
        return $this->required('IN_API_TOKEN');
    }

    public function webhookSigningSecret(): string
    {
        $v = $this->required('IN_WEBHOOK_SIGNING_SECRET');
        if (strlen($v) < 32) {
            throw new ConfigException('IN_WEBHOOK_SIGNING_SECRET must be at least 32 chars');
        }
        return $v;
    }

    public function allowPrivateNetworks(): bool
    {
        $v = $this->optional('SIDECAR_ALLOW_PRIVATE_NETWORKS');
        // Default ON because merchant-self-hosted-on-same-VM is the supported pattern.
        return $v === null || $v === '' || $v === '1' || strtolower($v) === 'true';
    }

    public function replayWindowSeconds(): int
    {
        $v = $this->optional('SIDECAR_REPLAY_WINDOW_SECONDS');
        if ($v === null || $v === '') {
            return 300;
        }
        if (!ctype_digit($v)) {
            throw new ConfigException('SIDECAR_REPLAY_WINDOW_SECONDS must be a non-negative integer');
        }
        $i = (int) $v;
        if ($i < 30 || $i > 3600) {
            throw new ConfigException('SIDECAR_REPLAY_WINDOW_SECONDS must be in [30, 3600]');
        }
        return $i;
    }

    public function tlsVerify(): bool
    {
        $v = $this->optional('SIDECAR_TLS_VERIFY');
        // Default ON; only "0"/"false" disables.
        if ($v === null || $v === '') {
            return true;
        }
        return !($v === '0' || strtolower($v) === 'false');
    }

    public function rateLimitPerMinute(): int
    {
        $v = $this->optional('SIDECAR_RATE_LIMIT_PER_MINUTE');
        if ($v === null || $v === '') {
            return 120;
        }
        if (!ctype_digit($v)) {
            throw new ConfigException('SIDECAR_RATE_LIMIT_PER_MINUTE must be a non-negative integer');
        }
        $i = (int) $v;
        if ($i < 1 || $i > 10000) {
            throw new ConfigException('SIDECAR_RATE_LIMIT_PER_MINUTE must be in [1, 10000]');
        }
        return $i;
    }

    /**
     * Per-state nexus allowlist (CP-3, v0.3.0). Returns an array of
     * upper-case 2-letter US state codes parsed from
     * `OST_NEXUS_STATES`. Empty = filter disabled.
     *
     * @return string[]
     */
    public function nexusStates(): array
    {
        $raw = $this->optional('OST_NEXUS_STATES');
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', strtoupper($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (preg_match('/^[A-Z]{2}$/', $p) === 1 && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private function required(string $key): string
    {
        $v = $this->env[$key] ?? '';
        if ($v === '') {
            throw new ConfigException(sprintf('Required env var %s is not set', $key));
        }
        return $v;
    }

    private function optional(string $key): ?string
    {
        return $this->env[$key] ?? null;
    }

    /** @return array<string, string> */
    private static function loadFromProcess(): array
    {
        $out = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        // Fall back to getenv() for keys not in $_ENV (CLI invocation often lacks them).
        $keys = [
            'OST_ENGINE_URL', 'OST_API_KEY', 'OST_TIMEOUT_SECONDS',
            'IN_API_URL', 'IN_API_TOKEN', 'IN_WEBHOOK_SIGNING_SECRET',
            'SIDECAR_ALLOW_PRIVATE_NETWORKS', 'SIDECAR_REPLAY_WINDOW_SECONDS',
            'SIDECAR_TLS_VERIFY', 'SIDECAR_RATE_LIMIT_PER_MINUTE',
            'OST_NEXUS_STATES',
        ];
        foreach ($keys as $k) {
            if (!isset($out[$k])) {
                $v = getenv($k);
                if (is_string($v) && $v !== '') {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * Suppress accidental var_dump / print_r exposure of secret keys.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        $masked = $this->env;
        foreach (['OST_API_KEY', 'IN_API_TOKEN', 'IN_WEBHOOK_SIGNING_SECRET'] as $k) {
            if (isset($masked[$k])) {
                $masked[$k] = '***REDACTED***';
            }
        }
        return $masked;
    }
}
