<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Cli;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\Config;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\ConfigException;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use Psr\Log\NullLogger;
use Throwable;

/**
 * `bin/console health:check` — verify the OST engine is reachable.
 *
 * Equivalent of the admin "Test Connection" button shipped in WooCom v0.5 /
 * Vendure v1.3 / Saleor v1.0 — but for the Invoice Ninja sidecar, which
 * has no admin UI. Catches typo'd engine URLs at config time rather than
 * at first invoice webhook.
 *
 * Exit codes:
 *   0  — engine reachable, /v1/health returned a sensible response
 *   1  — config error (missing or invalid OST_ENGINE_URL etc.)
 *   2  — engine unreachable / non-200 / malformed response
 *
 * Intentionally a class (not a one-shot script) so it's exercisable from
 * unit tests against an injected client + config without touching the
 * process environment or stdout.
 */
final class HealthCheckCommand
{
    public function __construct(
        private readonly OstClient $client,
        private readonly string $engineUrl,
        private readonly UrlValidator $urlValidator,
    ) {
    }

    /**
     * Build a command from process env + the real OST SDK client.
     *
     * @throws ConfigException When required env vars are missing/invalid.
     */
    public static function fromProcessEnv(?Config $config = null): self
    {
        $config ??= new Config();
        $client = new OstClient(
            baseUrl: $config->engineUrl(),
            apiKey: $config->engineApiKey(),
            timeoutSeconds: $config->engineTimeoutSeconds(),
        );
        return new self(
            $client,
            $config->engineUrl(),
            new UrlValidator($config->allowPrivateNetworks()),
        );
    }

    /**
     * Run the probe. Writes a single line to the given stream.
     *
     * Returns the exit code the CLI wrapper should pass to exit().
     *
     * @param resource $stream Stdout (or any writable resource).
     */
    public function run($stream): int
    {
        try {
            $this->urlValidator->validate($this->engineUrl);
        } catch (\InvalidArgumentException $e) {
            fwrite($stream, sprintf(
                "\xE2\x9C\x97 Engine URL rejected: %s\n",
                $e->getMessage(),
            ));
            return 1;
        }

        $start = microtime(true);
        try {
            $health = $this->client->health();
        } catch (OpenSalesTaxException $e) {
            fwrite($stream, sprintf(
                "\xE2\x9C\x97 Engine unreachable: %s\n",
                $e->getMessage(),
            ));
            return 2;
        } catch (Throwable $e) {
            fwrite($stream, sprintf(
                "\xE2\x9C\x97 Engine unreachable: %s: %s\n",
                $e::class,
                $e->getMessage(),
            ));
            return 2;
        }
        $rttMs = (int) round((microtime(true) - $start) * 1000);

        fwrite($stream, sprintf(
            "\xE2\x9C\x93 Engine v%s reachable — status=%s database=%s (RTT %d ms)\n",
            $health->version !== '' ? $health->version : 'unknown',
            $health->status !== '' ? $health->status : 'unknown',
            $health->databaseConnected ? 'connected' : 'disconnected',
            $rttMs,
        ));
        return 0;
    }
}
