<?php

/**
 * Smoke-test client that exercises the shim's Signer against the
 * verifier-only HTTP server in `smoke-server.php`. Run AFTER starting
 * the server. Prints the request header value and the HTTP response.
 */

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use GuzzleHttp\Client;

$secret = getenv('OST_SIDECAR_SIGNING_SECRET');
if ($secret === false || $secret === '') {
    fwrite(STDERR, "OST_SIDECAR_SIGNING_SECRET not set\n");
    exit(1);
}

$url = $argv[1] ?? 'http://127.0.0.1:8765/webhooks/invoice-ninja';
$body = (string) json_encode([
    'event_type' => 'invoice.created',
    'data' => ['id' => 'SMOKE-0001', 'total' => 100],
]);

$signer = new Signer($secret);
$header = $signer->sign($body);

echo "POST {$url}\n";
echo "X-Sidecar-Signature: {$header}\n";
echo "Body: {$body}\n";

$client = new Client();
$response = $client->request('POST', $url, [
    'headers' => [
        'Content-Type' => 'application/json',
        'X-Sidecar-Signature' => $header,
    ],
    'body' => $body,
    'http_errors' => false,
    'timeout' => 10,
]);

echo "HTTP " . $response->getStatusCode() . " " . $response->getReasonPhrase() . "\n";
echo (string) $response->getBody() . "\n";

exit($response->getStatusCode() === 200 ? 0 : 1);
