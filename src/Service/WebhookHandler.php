<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Service;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Http\Request;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Http\Response;
use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoiceNinjaClient;
use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoicePayload;
use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\PayloadException;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Sdk\EngineGateway;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\RateLimiter;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\ReplayCache;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\SignatureException;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\SignatureVerifier;
use Psr\Log\LoggerInterface;

/**
 * Sidecar webhook handler — the heart of the integration.
 *
 * Pipeline for `POST /webhooks/invoice-ninja`:
 *
 *   1. Rate-limit by source IP. Reject 429 if over.
 *   2. HMAC signature verify. Reject 401 on missing / invalid / stale.
 *   3. Replay check. Reject 409 on repeat within window.
 *   4. JSON decode + payload typing. Reject 422 on malformed.
 *   5. US + USD gate. Return 204 (no-op) on non-US / non-USD.
 *   6. Engine /v1/calculate. Fail-soft to no-op on engine error.
 *   7. Write back to Invoice Ninja (PUT /api/v1/invoices/{id}).
 *   8. Return 200 with a structured summary.
 *
 * Health endpoint: `GET /health` returns 200 + version. No auth.
 *
 * Constitution §10 disclaimer is included in every 200/204 response body
 * that references tax.
 */
final class WebhookHandler
{
    public const VERSION = '0.1.0-alpha.1';

    public function __construct(
        private readonly SignatureVerifier $signature,
        private readonly ReplayCache $replayCache,
        private readonly RateLimiter $rateLimiter,
        private readonly EngineGateway $engine,
        private readonly InvoiceNinjaClient $invoiceNinja,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Request $req): Response
    {
        $routed = $this->route($req);
        if ($routed !== null) {
            return $routed;
        }
        $rejected = $this->reject($req);
        if ($rejected !== null) {
            return $rejected;
        }
        return $this->processBody($req);
    }

    /**
     * Admission control + body parse + US/USD gate. Returns the rejection
     * Response if any stage decides to short-circuit, else null (caller
     * proceeds to engine call).
     */
    private function reject(Request $req): ?Response
    {
        return $this->admit($req)
            ?? $this->validateBody($req);
    }

    /**
     * Decode the body and apply US/USD gates. Returns a 204 for not-in-scope
     * payloads, a 400 on malformed JSON, or null when the payload is good.
     */
    private function validateBody(Request $req): ?Response
    {
        $parsed = $this->parse($req);
        return $parsed instanceof Response ? $parsed : null;
    }

    /**
     * Re-parse and process. handle() only reaches here when validateBody()
     * has already confirmed parse() succeeds, so this call always returns
     * an InvoicePayload.
     */
    private function processBody(Request $req): Response
    {
        $parsed = $this->parse($req);
        // @phpstan-ignore-next-line — provably an InvoicePayload here
        return $this->process($parsed);
    }

    /**
     * Path-level routing: health probe + 404. Returns null when the request
     * is the webhook endpoint and the rest of the pipeline should run.
     */
    private function route(Request $req): ?Response
    {
        if ($req->method === 'GET' && $req->path === '/health') {
            return Response::json(200, [
                'status' => 'ok',
                'service' => 'opensalestax-invoice-ninja',
                'version' => self::VERSION,
            ]);
        }
        if ($req->method !== 'POST' || $req->path !== '/webhooks/invoice-ninja') {
            return Response::plain(404, 'not found');
        }
        return null;
    }

    /**
     * Admission control: rate-limit, signature, replay. Returns a short-circuit
     * Response on rejection, or null when admitted.
     */
    private function admit(Request $req): ?Response
    {
        if (!$this->rateLimiter->allow($req->sourceIp)) {
            $this->logger->warning('webhook rate-limited', ['source_ip' => $req->sourceIp]);
            return Response::plain(429, 'rate limit exceeded');
        }
        $ts = $this->verifySignatureOrReject($req);
        if ($ts instanceof Response) {
            return $ts;
        }
        return $this->checkReplayOrReject($req, $ts);
    }

    /**
     * Verify the HMAC signature on the request. Returns the verified
     * timestamp on success, or a 401 Response on failure.
     */
    private function verifySignatureOrReject(Request $req): Response|int
    {
        try {
            return $this->signature->verify(
                $req->body,
                $req->header(SignatureVerifier::HEADER_NAME),
            );
        } catch (SignatureException $e) {
            $this->logger->warning('webhook signature rejected', [
                'source_ip' => $req->sourceIp,
                'reason' => $e->getMessage(),
            ]);
            return Response::plain(401, 'unauthorized');
        }
    }

    private function checkReplayOrReject(Request $req, int $ts): ?Response
    {
        if (!$this->replayCache->checkAndRemember($ts, $req->body)) {
            $this->logger->warning('webhook replay detected', ['source_ip' => $req->sourceIp]);
            return Response::plain(409, 'replay');
        }
        return null;
    }

    /**
     * Body parsing + US/USD gates. Returns a short-circuit Response or the
     * typed InvoicePayload on success.
     */
    private function parse(Request $req): Response|InvoicePayload
    {
        $decoded = $this->jsonDecodeBody($req);
        if ($decoded instanceof Response) {
            return $decoded;
        }
        return $this->buildPayload($decoded);
    }

    /**
     * @return array<mixed>|Response
     */
    private function jsonDecodeBody(Request $req): array|Response
    {
        try {
            $decoded = json_decode($req->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('webhook body not valid JSON', ['reason' => $e->getMessage()]);
            return Response::plain(400, 'malformed JSON');
        }
        if (!is_array($decoded)) {
            return Response::plain(400, 'JSON body must be an object');
        }
        return $decoded;
    }

    /**
     * @param array<mixed> $decoded
     */
    private function buildPayload(array $decoded): Response|InvoicePayload
    {
        try {
            return InvoicePayload::fromArray($decoded);
        } catch (PayloadException $e) {
            $this->logger->info('webhook payload not in scope (US + USD only)', [
                'reason' => $e->getMessage(),
            ]);
            return Response::noContent();
        }
    }

    /**
     * Engine call + write-back. Always returns a 200 Response — the result
     * field flags whether write-back actually succeeded.
     */
    private function process(InvoicePayload $payload): Response
    {
        $response = $this->engine->calculate($payload);
        if ($response === null) {
            return Response::json(200, [
                'invoice_id' => $payload->invoiceId,
                'applied' => false,
                'reason' => 'engine_unavailable',
                'disclaimer' => Response::DISCLAIMER,
            ]);
        }
        return $this->buildSuccessResponse($payload, $response);
    }

    private function buildSuccessResponse(
        InvoicePayload $payload,
        \OpenSalesTax\Responses\CalculateResponse $response,
    ): Response {
        $effectiveRatePct = self::weightedRatePercent($response);
        $applied = $this->invoiceNinja->applyTaxToInvoice(
            $payload->invoiceId,
            'OpenSalesTax',
            $effectiveRatePct,
        );
        return Response::json(200, [
            'invoice_id' => $payload->invoiceId,
            'applied' => $applied,
            'tax_rate_pct' => $effectiveRatePct,
            'tax_total' => $response->taxTotal,
            'subtotal' => $response->subtotal,
            'jurisdiction_count' => self::distinctJurisdictionCount($response),
            'disclaimer' => Response::DISCLAIMER,
        ]);
    }

    /**
     * Compute the effective rate to push back to Invoice Ninja's single
     * `tax_rate1` field — weighted by line subtotal so the per-line breakdown
     * still reconciles when Invoice Ninja's UI multiplies the rate by the
     * subtotal.
     *
     * Invoice Ninja v5 supports up to three named taxes per invoice in v0.1;
     * mapping per-jurisdiction lines into tax_name1/2/3 is a v0.2 enhancement.
     */
    public static function weightedRatePercent(\OpenSalesTax\Responses\CalculateResponse $response): float
    {
        $subtotal = (float) $response->subtotal;
        $tax = (float) $response->taxTotal;
        if ($subtotal <= 0.0) {
            return 0.0;
        }
        return round(($tax / $subtotal) * 100.0, 4);
    }

    private static function distinctJurisdictionCount(
        \OpenSalesTax\Responses\CalculateResponse $response,
    ): int {
        $seen = [];
        foreach ($response->lines as $line) {
            foreach ($line->jurisdictions as $j) {
                // JurisdictionRate exposes `name` per the SDK's v0.1 contract.
                if (isset($j->name) && is_string($j->name)) {
                    $seen[$j->name] = true;
                }
            }
        }
        return count($seen);
    }
}
