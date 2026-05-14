<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Http;

/**
 * Adapter between PHP's superglobals (php-fpm or built-in dev server) and
 * the sidecar's framework-agnostic Request/Response types.
 *
 * Pure functions; no static state. Easy to unit-test by passing arrays.
 */
final class PhpSapiAdapter
{
    /**
     * @param array<string, mixed> $server $_SERVER
     */
    public static function buildRequest(array $server, string $rawBody): Request
    {
        $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            ? strtoupper($server['REQUEST_METHOD'])
            : 'GET';
        $uri = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])
            ? $server['REQUEST_URI']
            : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $headers = self::extractHeaders($server);
        $sourceIp = self::extractSourceIp($server);

        return new Request($method, $path, $headers, $rawBody, $sourceIp);
    }

    /**
     * Emit the response via PHP's `header()` + body echo. Intended for php-fpm
     * or the built-in dev server. Tests don't exercise this path.
     */
    public static function emit(Response $response): void
    {
        http_response_code($response->status);
        foreach ($response->headers as $k => $v) {
            header(sprintf('%s: %s', $k, $v));
        }
        echo $response->body;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[self::titleCase($name)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[self::titleCase(str_replace('_', '-', $key))] = $value;
            }
        }
        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function extractSourceIp(array $server): string
    {
        // We intentionally trust REMOTE_ADDR only. X-Forwarded-For is rejected
        // unless the operator explicitly opts in (v0.2 — needs a trusted-proxy list).
        if (isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR'])) {
            return $server['REMOTE_ADDR'];
        }
        return 'unknown';
    }

    private static function titleCase(string $headerName): string
    {
        return implode('-', array_map(
            static fn (string $p): string => ucfirst(strtolower($p)),
            explode('-', $headerName),
        ));
    }
}
