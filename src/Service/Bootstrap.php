<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Service;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\Config;
use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoiceNinjaClient;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Logging\StderrLogger;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Sdk\EngineGateway;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\RateLimiter;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\ReplayCache;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\SignatureVerifier;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use GuzzleHttp\Client as GuzzleClient;
use OpenSalesTax\Client as OstClient;
use Psr\Log\LoggerInterface;

/**
 * Construct the WebhookHandler graph from a Config.
 *
 * Pure factory â€” no env reads here. The single entry point that touches
 * the process environment is Config::loadFromProcess(). Everything else
 * stays unit-testable.
 */
final class Bootstrap
{
    public static function fromConfig(Config $config, ?LoggerInterface $logger = null): WebhookHandler
    {
        $logger ??= new StderrLogger();

        $urlValidator = new UrlValidator($config->allowPrivateNetworks());

        $ostClient = new OstClient(
            baseUrl: $config->engineUrl(),
            apiKey: $config->engineApiKey(),
            timeoutSeconds: $config->engineTimeoutSeconds(),
        );
        $engineGateway = new EngineGateway(
            client: $ostClient,
            urlValidator: $urlValidator,
            engineUrl: $config->engineUrl(),
            logger: $logger,
        );

        $invoiceNinjaClient = new InvoiceNinjaClient(
            baseUrl: $config->invoiceNinjaUrl(),
            apiToken: $config->invoiceNinjaToken(),
            urlValidator: $urlValidator,
            http: new GuzzleClient(),
            logger: $logger,
            timeoutSeconds: $config->engineTimeoutSeconds(),
            tlsVerify: $config->tlsVerify(),
        );

        return new WebhookHandler(
            signature: new SignatureVerifier(
                secret: $config->webhookSigningSecret(),
                replayWindowSeconds: $config->replayWindowSeconds(),
            ),
            replayCache: new ReplayCache(ttlSeconds: $config->replayWindowSeconds()),
            rateLimiter: new RateLimiter(capacityPerMinute: $config->rateLimitPerMinute()),
            engine: $engineGateway,
            invoiceNinja: $invoiceNinjaClient,
            logger: $logger,
        );
    }
}
