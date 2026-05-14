<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Http;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle middleware factory that adds the X-Sidecar-Signature header to
 * outbound HTTP requests whose URL targets the configured sidecar.
 *
 * The middleware is intentionally narrow — it only signs requests that
 * match the configured sidecar URL prefix, so it can be safely installed
 * on Invoice Ninja's global Guzzle handler stack without leaking the
 * signing secret to unrelated outbound calls.
 */
final class SigningMiddleware
{
    /**
     * @param string   $sidecarUrlPrefix request URLs starting with this string get signed
     * @param string[] $methods          HTTP methods (uppercase) that should be signed
     */
    public function __construct(
        private readonly Signer $signer,
        private readonly string $headerName,
        private readonly string $sidecarUrlPrefix,
        private readonly array $methods = ['POST'],
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Return a Guzzle-compatible middleware callable. Signature mirrors
     * the form expected by `GuzzleHttp\HandlerStack::push()`.
     *
     * @return callable(callable): callable
     */
    public function __invoke(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $signed = $this->maybeSign($request);
                return $handler($signed, $options);
            };
        };
    }

    /**
     * Add the signed header iff the request matches the configured
     * sidecar URL prefix and method allow list. Otherwise return the
     * request untouched.
     */
    public function maybeSign(RequestInterface $request): RequestInterface
    {
        if (!$this->shouldSign($request)) {
            return $request;
        }
        $body = (string) $request->getBody();
        // Rewind the stream so the downstream client can still read it.
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }
        $header = $this->signer->sign($body);
        return $request->withHeader($this->headerName, $header);
    }

    /**
     * True when the request matches the enable flag, method allow list,
     * and configured sidecar URL prefix.
     */
    private function shouldSign(RequestInterface $request): bool
    {
        $methodAllowed = in_array(strtoupper($request->getMethod()), $this->methods, true);
        $urlMatches = $this->sidecarUrlPrefix !== ''
            && str_starts_with((string) $request->getUri(), $this->sidecarUrlPrefix);
        return $this->enabled && $methodAllowed && $urlMatches;
    }
}
