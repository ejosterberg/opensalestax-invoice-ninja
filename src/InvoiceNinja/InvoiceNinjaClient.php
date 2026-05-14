<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Minimal client for the Invoice Ninja v5 REST API.
 *
 * We only use one endpoint:
 *   PUT /api/v1/invoices/{id}    update an invoice (tax rate / total).
 *
 * Auth: `X-Api-Token: <token>` header on every call. The token authorizes
 * a server-side machine identity created in Invoice Ninja's admin UI.
 *
 * Retry policy: one retry on 409 Conflict (Invoice Ninja's optimistic
 * concurrency response when the invoice has been updated between our
 * webhook event and our write-back). All other 4xx are non-retryable;
 * 5xx logs but does not retry in v0.1 (queue-driven retry is a v0.2
 * concern — would need durable queue, not just in-memory).
 *
 * TLS verification is ON by default; the URL is SSRF-validated before
 * every request.
 */
final class InvoiceNinjaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly UrlValidator $urlValidator,
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds = 10.0,
        private readonly bool $tlsVerify = true,
    ) {
    }

    /**
     * Apply tax-rate metadata to an existing invoice.
     *
     * @param string $invoiceId Invoice Ninja invoice id (e.g. "Aabcd123").
     * @param string $taxName Human-readable tax description ("OpenSalesTax").
     * @param float  $taxRatePercent Effective rate as a percent (e.g. 7.875).
     * @return bool true on success; false on fatal write-back failure (caller
     *              logs at error level and decides whether to alert).
     */
    public function applyTaxToInvoice(
        string $invoiceId,
        string $taxName,
        float $taxRatePercent,
    ): bool {
        $url = $this->resolveInvoiceUrl($invoiceId);
        $payload = [
            'tax_name1' => $taxName,
            'tax_rate1' => round($taxRatePercent, 4),
        ];

        $result = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $outcome = $this->attemptPut($url, $payload, $invoiceId, $attempt);
            if ($outcome === 'ok') {
                $result = true;
                break;
            }
            if ($outcome === 'retry') {
                continue;
            }
            break; // 'fail'
        }
        return $result;
    }

    /**
     * Single PUT attempt. Returns one of:
     *   'ok'    — 2xx response, caller breaks out with success
     *   'retry' — 409 on first attempt, caller loops
     *   'fail'  — transport error or non-retryable status, caller breaks out
     *
     * @param array{tax_name1: string, tax_rate1: float} $payload
     */
    private function attemptPut(string $url, array $payload, string $invoiceId, int $attempt): string
    {
        $response = $this->putOrLogTransportError($url, $payload, $invoiceId, $attempt);
        if ($response === null) {
            return 'fail';
        }
        return $this->classifyStatus($response->getStatusCode(), $payload, $invoiceId, $attempt);
    }

    /**
     * Executes the PUT and returns the response, or logs + returns null on
     * transport failure.
     *
     * @param array{tax_name1: string, tax_rate1: float} $payload
     */
    private function putOrLogTransportError(
        string $url,
        array $payload,
        string $invoiceId,
        int $attempt,
    ): ?\Psr\Http\Message\ResponseInterface {
        try {
            return $this->http->put($url, [
                'headers' => [
                    'X-Api-Token' => $this->apiToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => $this->timeoutSeconds,
                'verify' => $this->tlsVerify,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('invoice-ninja PUT transport error', [
                'invoice_id' => $invoiceId,
                'attempt' => $attempt,
                'reason' => $e::class,
            ]);
            return null;
        }
    }

    /**
     * Map an HTTP status to one of 'ok' / 'retry' / 'fail'.
     *
     * @param array{tax_name1: string, tax_rate1: float} $payload
     */
    private function classifyStatus(int $status, array $payload, string $invoiceId, int $attempt): string
    {
        if ($status >= 200 && $status < 300) {
            $this->logger->info('invoice-ninja invoice updated', [
                'invoice_id' => $invoiceId,
                'http_status' => $status,
                'tax_rate' => $payload['tax_rate1'],
                'attempt' => $attempt,
            ]);
            return 'ok';
        }
        if ($status === 409 && $attempt === 0) {
            $this->logger->warning('invoice-ninja PUT conflict, retrying once', [
                'invoice_id' => $invoiceId,
                'http_status' => $status,
            ]);
            return 'retry';
        }
        $this->logger->error('invoice-ninja PUT failed', [
            'invoice_id' => $invoiceId,
            'http_status' => $status,
            'attempt' => $attempt,
        ]);
        return 'fail';
    }

    private function resolveInvoiceUrl(string $invoiceId): string
    {
        // SSRF check (no-op when SIDECAR_ALLOW_PRIVATE_NETWORKS=1, which is the
        // default for merchant-self-hosted). Either way, reject malformed URLs.
        $this->urlValidator->validate($this->baseUrl);
        if (preg_match('/^[A-Za-z0-9]{1,32}$/', $invoiceId) !== 1) {
            throw new \InvalidArgumentException('invoiceId is not a safe Invoice Ninja id');
        }
        return rtrim($this->baseUrl, '/') . '/api/v1/invoices/' . $invoiceId;
    }
}
