<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Tests\Unit\InvoiceNinja;

use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\InvoicePayload;
use EJOsterberg\OpenSalesTax\InvoiceNinja\InvoiceNinja\PayloadException;
use PHPUnit\Framework\TestCase;

final class InvoicePayloadTest extends TestCase
{
    /**
     * @return array{
     *     id: string,
     *     currency_id: string,
     *     client: array<string, mixed>,
     *     line_items: list<array<string, mixed>>
     * }
     */
    private static function validPayload(): array
    {
        return [
            'id' => 'Aabcd1234',
            'currency_id' => '1',
            'client' => [
                'id' => 'Xyz9876',
                'shipping_country_id' => '840',
                'shipping_postal_code' => '55401-1234',
            ],
            'line_items' => [
                ['cost' => '100.00', 'quantity' => '1', 'product_key' => 'WIDGET'],
                ['cost' => '50.00', 'quantity' => '2', 'product_key' => 'GIZMO'],
            ],
        ];
    }

    public function testParsesValidPayload(): void
    {
        $p = InvoicePayload::fromArray(self::validPayload());
        self::assertSame('Aabcd1234', $p->invoiceId);
        self::assertSame('55401', $p->zip5);
        self::assertSame('1234', $p->zip4);
        self::assertSame('USD', $p->currencyCode);
        self::assertSame('US', $p->countryCode);
        self::assertCount(2, $p->lines);
        self::assertSame('100', $p->lines[0]->subtotal);
        self::assertSame('100', $p->lines[1]->subtotal); // 50 * 2 = 100
    }

    public function testZipWithoutPlusFour(): void
    {
        $data = self::validPayload();
        $data['client']['shipping_postal_code'] = '55401';
        $p = InvoicePayload::fromArray($data);
        self::assertSame('55401', $p->zip5);
        self::assertNull($p->zip4);
    }

    public function testFallsBackToBillingPostalCode(): void
    {
        $data = self::validPayload();
        unset($data['client']['shipping_postal_code']);
        $data['client']['postal_code'] = '60601';
        $p = InvoicePayload::fromArray($data);
        self::assertSame('60601', $p->zip5);
    }

    public function testRejectsNonUsCountry(): void
    {
        $data = self::validPayload();
        $data['client']['shipping_country_id'] = '124'; // Canada
        $this->expectException(PayloadException::class);
        $this->expectExceptionMessageMatches('/not US/');
        InvoicePayload::fromArray($data);
    }

    public function testRejectsNonUsdCurrency(): void
    {
        $data = self::validPayload();
        $data['currency_id'] = '2';
        $this->expectException(PayloadException::class);
        $this->expectExceptionMessageMatches('/USD/');
        InvoicePayload::fromArray($data);
    }

    public function testAcceptsExpandedCurrencyCode(): void
    {
        $data = self::validPayload();
        unset($data['currency_id']);
        $data['client']['currency'] = ['code' => 'USD'];
        $p = InvoicePayload::fromArray($data);
        self::assertSame('USD', $p->currencyCode);
    }

    public function testRejectsExpandedNonUsdCurrencyCode(): void
    {
        $data = self::validPayload();
        unset($data['currency_id']);
        $data['client']['currency'] = ['code' => 'EUR'];
        $this->expectException(PayloadException::class);
        InvoicePayload::fromArray($data);
    }

    public function testMissingInvoiceIdIsRejected(): void
    {
        $data = self::validPayload();
        unset($data['id']);
        $this->expectException(PayloadException::class);
        InvoicePayload::fromArray($data);
    }

    public function testMissingClientIsRejected(): void
    {
        $data = self::validPayload();
        unset($data['client']);
        $this->expectException(PayloadException::class);
        InvoicePayload::fromArray($data);
    }

    public function testEmptyLineItemsIsRejected(): void
    {
        $data = self::validPayload();
        $data['line_items'] = [];
        $this->expectException(PayloadException::class);
        InvoicePayload::fromArray($data);
    }

    public function testInvalidPostalCodeIsRejected(): void
    {
        $data = self::validPayload();
        $data['client']['shipping_postal_code'] = 'NA';
        unset($data['client']['postal_code']);
        $this->expectException(PayloadException::class);
        $this->expectExceptionMessageMatches('/postal code/');
        InvoicePayload::fromArray($data);
    }

    public function testNegativeCostIsRejected(): void
    {
        $data = self::validPayload();
        $data['line_items'][0]['cost'] = '-1.00';
        $this->expectException(PayloadException::class);
        InvoicePayload::fromArray($data);
    }

    public function testNonDecimalCostIsRejected(): void
    {
        $data = self::validPayload();
        $data['line_items'][0]['cost'] = '100abc';
        $this->expectException(PayloadException::class);
        InvoicePayload::fromArray($data);
    }

    public function testInvoiceIdAsIntegerIsCoercedToString(): void
    {
        $data = self::validPayload();
        $data['id'] = 12345;
        $p = InvoicePayload::fromArray($data);
        self::assertSame('12345', $p->invoiceId);
    }

    public function testFractionalCostMultipliedCorrectly(): void
    {
        $data = self::validPayload();
        $data['line_items'] = [
            ['cost' => '12.34', 'quantity' => '3', 'product_key' => 'X'],
        ];
        $p = InvoicePayload::fromArray($data);
        // 12.34 * 3 = 37.02
        self::assertSame('37.02', $p->lines[0]->subtotal);
    }
}
