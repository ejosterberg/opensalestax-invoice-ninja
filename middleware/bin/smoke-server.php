<?php

/**
 * Tiny verifier-only HTTP server used by the shim's smoke test.
 *
 * Reads the request body, parses the X-Sidecar-Signature header,
 * recomputes the expected HMAC over `<t>.<body>`, and returns:
 *   - 200 OK + verified=1 if the signature matches
 *   - 401 + reason if it does not
 *
 * Run via `php -S 127.0.0.1:PORT smoke-server.php`. The secret comes
 * from the `OST_SIDECAR_SIGNING_SECRET` env var.
 */

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

$secret = getenv('OST_SIDECAR_SIGNING_SECRET');
if ($secret === false || $secret === '') {
    http_response_code(500);
    echo 'OST_SIDECAR_SIGNING_SECRET not set';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}

$body = file_get_contents('php://input') ?: '';

$headerName = 'HTTP_X_SIDECAR_SIGNATURE';
$headerValue = $_SERVER[$headerName] ?? null;
if (!is_string($headerValue) || $headerValue === '') {
    http_response_code(401);
    echo 'missing signature header';
    exit;
}

$t = null;
$v1 = null;
foreach (explode(',', $headerValue) as $part) {
    $kv = explode('=', trim($part), 2);
    if (count($kv) !== 2) {
        continue;
    }
    [$k, $val] = $kv;
    if ($k === 't' && ctype_digit($val)) {
        $t = (int) $val;
    } elseif ($k === 'v1' && preg_match('/^[0-9a-f]{64}$/', $val) === 1) {
        $v1 = $val;
    }
}

if ($t === null || $v1 === null) {
    http_response_code(401);
    echo 'malformed signature';
    exit;
}

$expected = hash_hmac('sha256', $t . '.' . $body, $secret);
if (!hash_equals($expected, $v1)) {
    http_response_code(401);
    echo 'signature mismatch';
    exit;
}

header('Content-Type: application/json');
echo json_encode(['verified' => true, 't' => $t]);
