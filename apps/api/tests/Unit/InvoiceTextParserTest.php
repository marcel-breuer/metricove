<?php

namespace Tests\Unit;

use App\Services\Receipts\InvoiceTextParser;
use PHPUnit\Framework\TestCase;

class InvoiceTextParserTest extends TestCase
{
    public function test_it_suggests_common_german_invoice_fields_with_european_amounts(): void
    {
        $suggestions = (new InvoiceTextParser)->parse(implode("\n", [
            'Werkstatt Nord',
            'Rechnungsnummer: RN-2026-104',
            'Rechnungsdatum: 29.10.2026',
            'Gesamtbetrag: 1.234,56 EUR',
            'MwSt. 19%: 197,10 EUR',
        ]));

        $this->assertSame([
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '1234.56',
            'currency' => 'EUR',
            'tax_amount' => '197.10',
        ], $suggestions);
    }

    public function test_it_suggests_common_english_invoice_fields_and_ignores_invalid_dates(): void
    {
        $suggestions = (new InvoiceTextParser)->parse(implode("\n", [
            'Northwind Services',
            'Invoice number: INV-420',
            'Invoice date: 2026-02-30',
            'Date: 2026-02-18',
            'Total due: EUR 99.50',
            'VAT amount: 15.88',
        ]));

        $this->assertSame('Northwind Services', $suggestions['merchant']);
        $this->assertSame('INV-420', $suggestions['invoice_number']);
        $this->assertSame('2026-02-18', $suggestions['invoice_date']);
        $this->assertSame('99.50', $suggestions['amount']);
        $this->assertSame('EUR', $suggestions['currency']);
        $this->assertSame('15.88', $suggestions['tax_amount']);
    }
}
