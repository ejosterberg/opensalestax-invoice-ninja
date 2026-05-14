<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Tests\Unit\Commands;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Commands\TestSigningCommand;
use EJOsterberg\OpenSalesTax\InvoiceNinja\Shim\Signing\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Direct-invocation tests for the artisan command.
 *
 * We avoid Symfony's CommandTester here because it triggers Laravel's
 * console prompt-configuration trait which expects a full Foundation
 * Application. Driving the command's run() method with raw Symfony
 * Input/Output objects exercises the same code path with no harness.
 */
final class TestSigningCommandTest extends TestCase
{
    private const SECRET = 'command-test-secret-32-charsXXXX';

    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $captured;

    /**
     * @param array<int, Response>  $queuedResponses
     * @param array<string, mixed>  $config
     */
    private function makeCommand(array $queuedResponses, array $config): TestSigningCommand
    {
        $container = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->instance('config', new Repository(['opensalestax-sidecar-shim' => $config]));
        Container::setInstance($container);

        $this->captured = [];
        $mock = new MockHandler($queuedResponses);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler): callable {
            return function ($request, $options) use ($handler) {
                $this->captured[] = $request;
                return $handler($request, $options);
            };
        });
        $http = new Client(['handler' => $stack]);

        $signer = new Signer(self::SECRET, static fn (): int => 1_700_000_000);
        $command = new TestSigningCommand($signer, $http);
        $command->setLaravel($container);

        return $command;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(TestSigningCommand $command, BufferedOutput $output, array $options = []): int
    {
        $defaults = ['--url' => null, '--payload' => null];
        $merged = array_merge($defaults, $options);
        $input = new ArrayInput($merged, $command->getDefinition());
        return $command->run($input, $output);
    }

    public function testHappyPath200ReturnsSuccess(): void
    {
        $command = $this->makeCommand(
            [new Response(200, [], '{"status":"ok"}')],
            [
                'secret' => self::SECRET,
                'sidecar_url' => 'http://sidecar.local/webhooks/in',
                'header_name' => 'X-Sidecar-Signature',
                'enabled' => true,
            ],
        );
        $output = new BufferedOutput();

        $exit = $this->runCommand($command, $output);
        self::assertSame(0, $exit);
        $text = $output->fetch();
        self::assertStringContainsString('HTTP 200', $text);
        self::assertStringContainsString('X-Sidecar-Signature: t=1700000000,v1=', $text);
        self::assertCount(1, $this->captured);
        $req = $this->captured[0];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('http://sidecar.local/webhooks/in', (string) $req->getUri());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^t=1700000000,v1=[0-9a-f]{64}$/',
            $req->getHeaderLine('X-Sidecar-Signature'),
        );
    }

    public function testFailureExitCodeOn500(): void
    {
        $command = $this->makeCommand(
            [new Response(500, [], 'boom')],
            [
                'secret' => self::SECRET,
                'sidecar_url' => 'http://sidecar.local/webhooks/in',
                'header_name' => 'X-Sidecar-Signature',
                'enabled' => true,
            ],
        );
        $output = new BufferedOutput();

        $exit = $this->runCommand($command, $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString('HTTP 500', $output->fetch());
    }

    public function testMissingUrlIsAnError(): void
    {
        $command = $this->makeCommand(
            [],
            [
                'secret' => self::SECRET,
                'sidecar_url' => '',
                'header_name' => 'X-Sidecar-Signature',
                'enabled' => true,
            ],
        );
        $output = new BufferedOutput();

        $exit = $this->runCommand($command, $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString('No sidecar URL configured', $output->fetch());
    }

    public function testUrlOptionOverridesConfig(): void
    {
        $command = $this->makeCommand(
            [new Response(204)],
            [
                'secret' => self::SECRET,
                'sidecar_url' => 'http://sidecar.local/webhooks/in',
                'header_name' => 'X-Sidecar-Signature',
                'enabled' => true,
            ],
        );
        $output = new BufferedOutput();

        $exit = $this->runCommand($command, $output, ['--url' => 'http://override.local/hook']);
        self::assertSame(0, $exit);
        self::assertSame('http://override.local/hook', (string) $this->captured[0]->getUri());
    }

    public function testPayloadOptionOverridesDefault(): void
    {
        $command = $this->makeCommand(
            [new Response(204)],
            [
                'secret' => self::SECRET,
                'sidecar_url' => 'http://sidecar.local/webhooks/in',
                'header_name' => 'X-Sidecar-Signature',
                'enabled' => true,
            ],
        );
        $output = new BufferedOutput();

        $exit = $this->runCommand($command, $output, ['--payload' => '{"override":1}']);
        self::assertSame(0, $exit);
        self::assertSame('{"override":1}', (string) $this->captured[0]->getBody());
    }
}
