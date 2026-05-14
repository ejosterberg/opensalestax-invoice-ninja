<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Sdk;

use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoicePayload;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use OpenSalesTax\Address;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\LineItem;
use OpenSalesTax\Responses\CalculateResponse;
use Psr\Log\LoggerInterface;

/**
 * Bridge between the sidecar's InvoicePayload type and the OpenSalesTax PHP
 * SDK (`ejosterberg/opensalestax` ^0.1).
 *
 * Failure model: any SDK exception is converted to a single
 * EngineException so the caller has one type to catch. The webhook handler
 * fails soft by default — engine errors do NOT block invoice creation,
 * they just leave the invoice untaxed and log loudly.
 */
final class EngineGateway
{
    public function __construct(
        private readonly OstClient $client,
        private readonly UrlValidator $urlValidator,
        private readonly string $engineUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the engine response, or null on any fail-soft path (logged).
     *
     * The webhook handler interprets a null return as "leave the invoice
     * unchanged" — the merchant sees no tax line, the operator sees a log.
     */
    public function calculate(InvoicePayload $payload): ?CalculateResponse
    {
        try {
            $this->urlValidator->validate($this->engineUrl);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('engine URL rejected by SSRF validator', [
                'reason' => $e->getMessage(),
            ]);
            return null;
        }

        try {
            $address = new Address($payload->zip5, $payload->zip4);
            $lines = [];
            foreach ($payload->lines as $line) {
                $lines[] = new LineItem($line->subtotal, 'general');
            }
            $start = microtime(true);
            $response = $this->client->calculate($address, $lines);
            $rttMs = (int) round((microtime(true) - $start) * 1000);
            $this->logger->info('engine /v1/calculate ok', [
                'invoice_id' => $payload->invoiceId,
                'zip5' => $payload->zip5,
                'line_count' => count($payload->lines),
                'tax_total' => $response->taxTotal,
                'rtt_ms' => $rttMs,
            ]);
            return $response;
        } catch (OpenSalesTaxException $e) {
            $this->logger->error('engine call failed (fail-soft)', [
                'invoice_id' => $payload->invoiceId,
                'reason' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
