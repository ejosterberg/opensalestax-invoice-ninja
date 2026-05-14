<?php

/**
 * Sidecar HTTP entry-point.
 *
 * Deploy patterns:
 *
 *   - php-fpm + nginx: map all requests under your chosen path to this script
 *     (set up a fastcgi_param REQUEST_URI and pipe php://input as the body).
 *   - Built-in dev server: php -S 0.0.0.0:8181 bin/sidecar.php
 *
 * The sidecar is stateless apart from the in-memory replay + rate-limit caches;
 * in production you should run a single process per host or accept that those
 * caches are per-process. See `docs/SECURITY-REVIEW.md` for the trade-off.
 *
 * @license Apache-2.0
 */

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\Config;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\ConfigException;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Http\PhpSapiAdapter;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Http\Response;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Service\Bootstrap;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "missing vendor/autoload.php — run `composer install` first.\n";
    exit(1);
}
require $autoload;

try {
    $config = new Config();
    $handler = Bootstrap::fromConfig($config);
} catch (ConfigException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "sidecar configuration error: " . $e->getMessage() . "\n";
    exit(1);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}
$request = PhpSapiAdapter::buildRequest($_SERVER, $rawBody);
$response = $handler->handle($request);
PhpSapiAdapter::emit($response);
