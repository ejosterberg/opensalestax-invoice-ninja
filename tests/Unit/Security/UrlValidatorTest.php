<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Security;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Security\UrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    public function testEmptyUrlIsRejected(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(InvalidArgumentException::class);
        $v->validate('');
    }

    public function testValidHttpsWithPrivateAllowedReturnsNull(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        self::assertNull($v->validate('https://ost.example.com'));
    }

    public function testValidHttpWithPrivateAllowedReturnsNull(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        self::assertNull($v->validate('http://ost.example.com:8080'));
    }

    public function testMalformedUrlIsRejected(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(InvalidArgumentException::class);
        $v->validate('not a url');
    }

    public function testFileSchemeIsRejected(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(InvalidArgumentException::class);
        $v->validate('file:///etc/passwd');
    }

    public function testFtpSchemeIsRejected(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(InvalidArgumentException::class);
        $v->validate('ftp://ost.example.com');
    }

    public function testPrivateIpAllowedWhenFlagOn(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        self::assertNull($v->validate('http://10.0.0.5:8080'));
        self::assertNull($v->validate('http://127.0.0.1:8080'));
        self::assertNull($v->validate('http://192.168.1.10:8080'));
    }

    public function testPrivateIpRejectedWhenFlagOff(): void
    {
        $v = new UrlValidator(
            allowPrivateNetworks: false,
            hostResolver: static fn (string $host): string => $host,
        );
        $this->expectException(InvalidArgumentException::class);
        $v->validate('http://10.0.0.5:8080');
    }

    public function testLinkLocalMetadataAddressBlocked(): void
    {
        $v = new UrlValidator(
            allowPrivateNetworks: false,
            hostResolver: static fn (string $host): string => '169.254.169.254',
        );
        $this->expectException(InvalidArgumentException::class);
        $v->validate('http://metadata.example/');
    }

    public function testPublicIpAcceptedAndReturnedForPinning(): void
    {
        $v = new UrlValidator(
            allowPrivateNetworks: false,
            hostResolver: static fn (string $host): string => '8.8.8.8',
        );
        self::assertSame('8.8.8.8', $v->validate('https://ost.example.com'));
    }

    public function testUnresolvableHostRejected(): void
    {
        $v = new UrlValidator(
            allowPrivateNetworks: false,
            hostResolver: static fn (string $host): ?string => null,
        );
        $this->expectException(InvalidArgumentException::class);
        $v->validate('https://no-such-host.invalid');
    }
}
