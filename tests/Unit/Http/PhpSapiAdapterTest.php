<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\Http;

use EJOsterberg\OpenSalesTax\InvoiceNinja\Http\PhpSapiAdapter;
use PHPUnit\Framework\TestCase;

final class PhpSapiAdapterTest extends TestCase
{
    public function testBuildsRequestFromServerSuperglobal(): void
    {
        $server = [
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/webhooks/invoice-ninja?source=in',
            'HTTP_X_SIDECAR_SIGNATURE' => 't=1,v1=abc',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.7',
        ];
        $req = PhpSapiAdapter::buildRequest($server, '{"id":"X"}');
        self::assertSame('POST', $req->method);
        self::assertSame('/webhooks/invoice-ninja', $req->path);
        self::assertSame('{"id":"X"}', $req->body);
        self::assertSame('203.0.113.7', $req->sourceIp);
        self::assertSame('t=1,v1=abc', $req->header('X-Sidecar-Signature'));
        self::assertSame('application/json', $req->header('content-type'));
    }

    public function testDefaultsSourceIpToUnknown(): void
    {
        $req = PhpSapiAdapter::buildRequest([], '');
        self::assertSame('unknown', $req->sourceIp);
        self::assertSame('GET', $req->method);
        self::assertSame('/', $req->path);
    }
}
