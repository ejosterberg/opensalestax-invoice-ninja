<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Provider;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Http\SigningMiddleware;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\ShimConfigurationException;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\SidecarShimServiceProvider;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the service provider's container bindings without booting
 * the full Laravel framework. We feed it a minimal Container + Config
 * repository and assert that the public bindings come out wired
 * correctly.
 */
final class SidecarShimServiceProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function makeAppWithConfig(array $config): Container
    {
        $app = new Container();
        $repository = new Repository(['opensalestax-sidecar-shim' => $config]);
        $app->instance('config', $repository);
        Container::setInstance($app);
        return $app;
    }

    public function testRegisterBindsSignerSingleton(): void
    {
        $app = $this->makeAppWithConfig([
            'secret' => 'provider-test-secret-32-charsXXXX',
            'sidecar_url' => 'http://sidecar.local/webhooks/in',
            'header_name' => 'X-Sidecar-Signature',
            'enabled' => true,
        ]);

        $provider = new SidecarShimServiceProvider($app);
        $provider->register();

        $a = $app->make(Signer::class);
        $b = $app->make(Signer::class);
        self::assertSame($a, $b);
        self::assertInstanceOf(Signer::class, $a);
    }

    public function testRegisterBindsMiddlewareSingleton(): void
    {
        $app = $this->makeAppWithConfig([
            'secret' => 'provider-test-secret-32-charsXXXX',
            'sidecar_url' => 'http://sidecar.local/webhooks/in',
            'header_name' => 'X-Sidecar-Signature',
            'enabled' => true,
        ]);

        $provider = new SidecarShimServiceProvider($app);
        $provider->register();

        $mw = $app->make(SigningMiddleware::class);
        self::assertInstanceOf(SigningMiddleware::class, $mw);
        self::assertSame($mw, $app->make(SigningMiddleware::class));
    }

    public function testMissingSecretThrowsOnResolution(): void
    {
        $app = $this->makeAppWithConfig([
            'secret' => '',
            'sidecar_url' => 'http://sidecar.local/webhooks/in',
        ]);

        $provider = new SidecarShimServiceProvider($app);
        $provider->register();

        $this->expectException(ShimConfigurationException::class);
        $this->expectExceptionMessage('signing secret is empty');
        $app->make(Signer::class);
    }

    public function testMiddlewareIsDisabledWhenConfigDisables(): void
    {
        $app = $this->makeAppWithConfig([
            'secret' => 'provider-test-secret-32-charsXXXX',
            'sidecar_url' => 'http://sidecar.local/webhooks/in',
            'header_name' => 'X-Sidecar-Signature',
            'enabled' => false,
        ]);

        $provider = new SidecarShimServiceProvider($app);
        $provider->register();

        $mw = $app->make(SigningMiddleware::class);
        // Smoke-test: when disabled, maybeSign returns request untouched.
        $req = new \GuzzleHttp\Psr7\Request('POST', 'http://sidecar.local/webhooks/in', [], '{}');
        $out = $mw->maybeSign($req);
        self::assertFalse($out->hasHeader('X-Sidecar-Signature'));
    }

    public function testConfigKeyConstantMatchesFileName(): void
    {
        self::assertSame('opensalestax-sidecar-shim', SidecarShimServiceProvider::CONFIG_KEY);
    }

    public function testDefaultConfigFileLoadsAndContainsExpectedKeys(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/opensalestax-sidecar-shim.php';
        self::assertArrayHasKey('secret', $config);
        self::assertArrayHasKey('sidecar_url', $config);
        self::assertArrayHasKey('header_name', $config);
        self::assertArrayHasKey('enabled', $config);
        self::assertArrayHasKey('events', $config);
        self::assertSame('X-Sidecar-Signature', $config['header_name']);
        self::assertTrue($config['enabled']);
    }
}
