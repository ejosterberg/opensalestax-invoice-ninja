<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal stderr JSON logger. No file handles, no buffering, no PII â€”
 * just a one-line JSON event per call.
 *
 * Anything matching the redact list is replaced with "***REDACTED***"
 * before serialization. The redact list is the set of keys the sidecar
 * promises never to log.
 */
final class StderrLogger extends AbstractLogger
{
    private const REDACT_KEYS = [
        'api_token', 'api_key', 'X-Api-Token', 'Authorization',
        'IN_API_TOKEN', 'OST_API_KEY', 'IN_WEBHOOK_SIGNING_SECRET',
        'signature', 'secret', 'password', 'token',
    ];

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $payload = [
            'ts' => date('c'),
            'level' => is_string($level) ? $level : 'info',
            'msg' => (string) $message,
        ];
        if ($context !== []) {
            $payload['ctx'] = self::redact($context);
        }
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        $stderr = fopen('php://stderr', 'wb');
        if ($stderr === false) {
            return;
        }
        fwrite($stderr, $line . "\n");
        fclose($stderr);
    }

    /**
     * @param array<mixed> $ctx
     * @return array<mixed>
     */
    private static function redact(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            if (is_string($k) && in_array($k, self::REDACT_KEYS, true)) {
                $out[$k] = '***REDACTED***';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
