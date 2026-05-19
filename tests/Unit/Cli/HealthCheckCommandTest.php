<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Cli;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Cli\HealthCheckCommand;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSalesTax\Client as OstClient;
use PHPUnit\Framework\TestCase;

final class HealthCheckCommandTest extends TestCase
{
    /**
     * @param list<GuzzleResponse|\Throwable> $queue
     */
    private function command(
        array $queue,
        string $engineUrl = 'https://ost.example.com',
        bool $allowPrivate = true,
    ): HealthCheckCommand {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);

        $ost = new OstClient(
            baseUrl: $engineUrl,
            httpClient: $guzzle,
        );
        return new HealthCheckCommand(
            client: $ost,
            engineUrl: $engineUrl,
            urlValidator: new UrlValidator(allowPrivateNetworks: $allowPrivate),
        );
    }

    /** @return array{int, string} */
    private function runAndCapture(HealthCheckCommand $cmd): array
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);
        try {
            $exitCode = $cmd->run($stream);
            rewind($stream);
            $out = stream_get_contents($stream);
            self::assertIsString($out);
            return [$exitCode, $out];
        } finally {
            fclose($stream);
        }
    }

    public function testHealthyEngineReturnsZeroAndCheckmark(): void
    {
        $body = json_encode([
            'status' => 'ok',
            'version' => '0.59.0',
            'database_connected' => true,
        ], JSON_THROW_ON_ERROR);
        [$exit, $out] = $this->runAndCapture($this->command([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $body),
        ]));

        self::assertSame(0, $exit);
        self::assertStringContainsString('0.59.0', $out);
        self::assertStringContainsString('connected', $out);
        self::assertStringContainsString("\xE2\x9C\x93", $out);
    }

    public function testEngineNon200ReturnsTwoAndCross(): void
    {
        [$exit, $out] = $this->runAndCapture($this->command([
            new GuzzleResponse(503, [], 'unavailable'),
        ]));

        self::assertSame(2, $exit);
        self::assertStringContainsString('unreachable', $out);
        self::assertStringContainsString("\xE2\x9C\x97", $out);
    }

    public function testTransportErrorReturnsTwo(): void
    {
        [$exit, $out] = $this->runAndCapture($this->command([
            new ConnectException(
                'Connection refused',
                new GuzzleRequest('GET', 'https://ost.example.com/v1/health'),
            ),
        ]));

        self::assertSame(2, $exit);
        self::assertStringContainsString('unreachable', $out);
    }

    public function testPrivateUrlRejectedReturnsOne(): void
    {
        [$exit, $out] = $this->runAndCapture($this->command(
            queue: [],
            engineUrl: 'http://127.0.0.1:8080',
            allowPrivate: false,
        ));

        self::assertSame(1, $exit);
        self::assertStringContainsString('rejected', $out);
    }

    public function testDbDisconnectedStillExitsZero(): void
    {
        $body = json_encode([
            'status' => 'degraded',
            'version' => '0.59.0',
            'database_connected' => false,
        ], JSON_THROW_ON_ERROR);
        [$exit, $out] = $this->runAndCapture($this->command([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $body),
        ]));

        // /v1/health returning a response (even degraded) is still "reachable".
        // We surface the state in the message — operator decides what to do.
        self::assertSame(0, $exit);
        self::assertStringContainsString('disconnected', $out);
        self::assertStringContainsString('degraded', $out);
    }
}
