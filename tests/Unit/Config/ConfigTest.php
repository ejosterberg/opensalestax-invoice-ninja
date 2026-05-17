<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Config;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\Config;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function validEnv(): array
    {
        return [
            'OST_ENGINE_URL' => 'https://ost.example.com',
            'IN_API_URL' => 'https://invoices.example.com',
            'IN_API_TOKEN' => 'token-abc',
            'IN_WEBHOOK_SIGNING_SECRET' => str_repeat('a', 64),
        ];
    }

    public function testReadsRequiredKeys(): void
    {
        $c = new Config(self::validEnv());
        self::assertSame('https://ost.example.com', $c->engineUrl());
        self::assertSame('https://invoices.example.com', $c->invoiceNinjaUrl());
        self::assertSame('token-abc', $c->invoiceNinjaToken());
        self::assertSame(str_repeat('a', 64), $c->webhookSigningSecret());
    }

    public function testMissingRequiredKeyThrows(): void
    {
        $env = self::validEnv();
        unset($env['OST_ENGINE_URL']);
        $this->expectException(ConfigException::class);
        (new Config($env))->engineUrl();
    }

    public function testShortWebhookSecretThrows(): void
    {
        $env = self::validEnv();
        $env['IN_WEBHOOK_SIGNING_SECRET'] = 'too-short';
        $this->expectException(ConfigException::class);
        (new Config($env))->webhookSigningSecret();
    }

    public function testEngineApiKeyOptional(): void
    {
        $c = new Config(self::validEnv());
        self::assertNull($c->engineApiKey());

        $env = self::validEnv();
        $env['OST_API_KEY'] = 'secret-key';
        self::assertSame('secret-key', (new Config($env))->engineApiKey());
    }

    public function testEngineTimeoutDefaultAndBounds(): void
    {
        $c = new Config(self::validEnv());
        self::assertSame(10.0, $c->engineTimeoutSeconds());

        $env = self::validEnv();
        $env['OST_TIMEOUT_SECONDS'] = '15';
        self::assertSame(15.0, (new Config($env))->engineTimeoutSeconds());

        $env['OST_TIMEOUT_SECONDS'] = '0';
        $this->expectException(ConfigException::class);
        (new Config($env))->engineTimeoutSeconds();
    }

    public function testEngineTimeoutRejectsNonNumeric(): void
    {
        $env = self::validEnv();
        $env['OST_TIMEOUT_SECONDS'] = 'soon';
        $this->expectException(ConfigException::class);
        (new Config($env))->engineTimeoutSeconds();
    }

    public function testAllowPrivateNetworksDefaultsTrue(): void
    {
        $c = new Config(self::validEnv());
        self::assertTrue($c->allowPrivateNetworks());

        $env = self::validEnv();
        $env['SIDECAR_ALLOW_PRIVATE_NETWORKS'] = '0';
        self::assertFalse((new Config($env))->allowPrivateNetworks());
    }

    public function testReplayWindowDefaultsAndBounds(): void
    {
        $c = new Config(self::validEnv());
        self::assertSame(300, $c->replayWindowSeconds());

        $env = self::validEnv();
        $env['SIDECAR_REPLAY_WINDOW_SECONDS'] = '60';
        self::assertSame(60, (new Config($env))->replayWindowSeconds());

        $env['SIDECAR_REPLAY_WINDOW_SECONDS'] = '20';
        $this->expectException(ConfigException::class);
        (new Config($env))->replayWindowSeconds();
    }

    public function testTlsVerifyDefaultsOn(): void
    {
        $c = new Config(self::validEnv());
        self::assertTrue($c->tlsVerify());

        $env = self::validEnv();
        $env['SIDECAR_TLS_VERIFY'] = '0';
        self::assertFalse((new Config($env))->tlsVerify());

        $env['SIDECAR_TLS_VERIFY'] = 'false';
        self::assertFalse((new Config($env))->tlsVerify());
    }

    public function testRateLimitDefaultsAndBounds(): void
    {
        $c = new Config(self::validEnv());
        self::assertSame(120, $c->rateLimitPerMinute());

        $env = self::validEnv();
        $env['SIDECAR_RATE_LIMIT_PER_MINUTE'] = '60';
        self::assertSame(60, (new Config($env))->rateLimitPerMinute());

        $env['SIDECAR_RATE_LIMIT_PER_MINUTE'] = '0';
        $this->expectException(ConfigException::class);
        (new Config($env))->rateLimitPerMinute();
    }

    public function testDebugInfoMasksSecrets(): void
    {
        $env = self::validEnv();
        $env['OST_API_KEY'] = 'plain-secret';
        $c = new Config($env);
        $dump = $c->__debugInfo();
        self::assertSame('***REDACTED***', $dump['IN_API_TOKEN']);
        self::assertSame('***REDACTED***', $dump['IN_WEBHOOK_SIGNING_SECRET']);
        self::assertSame('***REDACTED***', $dump['OST_API_KEY']);
        // Public fields not masked.
        self::assertSame('https://ost.example.com', $dump['OST_ENGINE_URL']);
    }
}
