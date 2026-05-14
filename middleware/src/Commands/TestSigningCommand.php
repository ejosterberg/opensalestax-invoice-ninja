<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Commands;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Psr\Http\Message\ResponseInterface;

/**
 * `php artisan opensalestax-sidecar-shim:test`
 *
 * Posts a sample webhook payload to the configured sidecar URL with a
 * freshly-signed header and prints the HTTP response. Useful for
 * one-shot install verification — if this command prints `200 OK` (or
 * `204 No Content` for out-of-scope invoices) the shim is wired up
 * correctly.
 */
final class TestSigningCommand extends Command
{
    /** @var string */
    protected $signature = 'opensalestax-sidecar-shim:test
        {--url= : Override the sidecar URL from config}
        {--payload= : Override the sample JSON body (raw string)}';

    /** @var string */
    protected $description = 'Send a signed test webhook to the OpenSalesTax sidecar and print the response.';

    private ?ClientInterface $httpClient;

    public function __construct(
        private readonly Signer $signer,
        ?ClientInterface $httpClient = null,
    ) {
        parent::__construct();
        $this->httpClient = $httpClient;
    }

    public function handle(): int
    {
        $config = $this->loadConfig();

        $urlOpt = $this->option('url');
        $urlFromConfig = is_string($config['sidecar_url'] ?? null) ? $config['sidecar_url'] : '';
        $url = is_string($urlOpt) && $urlOpt !== '' ? $urlOpt : $urlFromConfig;

        if ($url === '') {
            $this->error('No sidecar URL configured. Set OST_SIDECAR_URL in .env or pass --url=.');
            return self::FAILURE;
        }

        $payloadOpt = $this->option('payload');
        $payload = is_string($payloadOpt) && $payloadOpt !== ''
            ? $payloadOpt
            : (string) json_encode([
                'event_type' => 'invoice.created',
                'data' => ['id' => 'TEST0001'],
            ], JSON_THROW_ON_ERROR);

        $headerName = is_string($config['header_name'] ?? null)
            ? $config['header_name']
            : 'X-Sidecar-Signature';

        $header = $this->signer->sign($payload);

        $this->line('POST ' . $url);
        $this->line($headerName . ': ' . $header);

        try {
            $response = $this->client()->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    $headerName => $header,
                ],
                'body' => $payload,
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            $this->error('HTTP request failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->printResponse($response);
        return $response->getStatusCode() >= 400 ? self::FAILURE : self::SUCCESS;
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ??= new Client();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        /** @var mixed $repo */
        $repo = $this->laravel->make('config');
        if (!is_object($repo) || !method_exists($repo, 'get')) {
            return [];
        }
        /** @var mixed $config */
        $config = $repo->get('opensalestax-sidecar-shim', []);
        return is_array($config) ? $config : [];
    }

    private function printResponse(ResponseInterface $response): void
    {
        $this->line(sprintf(
            'HTTP %d %s',
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        ));
        $body = (string) $response->getBody();
        if ($body !== '') {
            $this->line($body);
        }
    }
}
