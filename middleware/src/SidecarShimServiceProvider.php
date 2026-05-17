<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Commands\TestSigningCommand;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Http\SigningMiddleware;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the OpenSalesTax sidecar signing shim.
 *
 * Responsibilities:
 *   - merge `config/opensalestax-sidecar-shim.php` into the host app's config
 *   - bind `Signer` and `SigningMiddleware` as singletons
 *   - register the `opensalestax-sidecar-shim:test` artisan command
 *   - publish the config file under the `opensalestax-sidecar-shim-config` tag
 */
final class SidecarShimServiceProvider extends ServiceProvider
{
    public const CONFIG_KEY = 'opensalestax-sidecar-shim';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/opensalestax-sidecar-shim.php',
            self::CONFIG_KEY,
        );

        $this->app->singleton(Signer::class, function (Container $app): Signer {
            $config = self::extractConfig($app);
            $secret = is_string($config['secret'] ?? null) ? $config['secret'] : '';
            if ($secret === '') {
                throw new ShimConfigurationException(
                    'OpenSalesTax sidecar shim: signing secret is empty. ' .
                    'Set OST_SIDECAR_SIGNING_SECRET in your .env file.',
                );
            }
            return new Signer($secret);
        });

        $this->app->singleton(SigningMiddleware::class, function (Container $app): SigningMiddleware {
            $config = self::extractConfig($app);
            $signer = $app->make(Signer::class);
            $headerName = is_string($config['header_name'] ?? null)
                ? $config['header_name']
                : 'X-Sidecar-Signature';
            $sidecarUrl = is_string($config['sidecar_url'] ?? null) ? $config['sidecar_url'] : '';
            $enabled = (bool) ($config['enabled'] ?? true);
            return new SigningMiddleware(
                signer: $signer,
                headerName: $headerName,
                sidecarUrlPrefix: $sidecarUrl,
                enabled: $enabled,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/opensalestax-sidecar-shim.php'
                    => $this->configPath('opensalestax-sidecar-shim.php'),
            ], 'opensalestax-sidecar-shim-config');

            $this->commands([
                TestSigningCommand::class,
            ]);
        }
    }

    /**
     * Pull the shim's config block out of the container in a way that
     * keeps PHPStan happy.
     *
     * @return array<string, mixed>
     */
    private static function extractConfig(Container $app): array
    {
        /** @var mixed $repo */
        $repo = $app->make('config');
        if (!is_object($repo) || !method_exists($repo, 'get')) {
            return [];
        }
        /** @var mixed $config */
        $config = $repo->get(self::CONFIG_KEY, []);
        return is_array($config) ? $config : [];
    }

    /**
     * Resolve the host application's config_path() if available, falling
     * back to a relative path for non-Laravel test harnesses.
     */
    private function configPath(string $filename): string
    {
        if (!function_exists('config_path')) {
            return 'config/' . $filename;
        }
        /** @var string */
        return config_path($filename);
    }
}
