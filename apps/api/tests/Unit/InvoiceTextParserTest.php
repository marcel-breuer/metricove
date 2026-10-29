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
            'fuel_liters' => null,
            'fuel_unit_price' => null,
            'fuel_odometer_value' => null,
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

    public function test_it_suggests_fuel_fields_and_requires_review_for_uncertain_and_missing_core_values(): void
    {
        $parser = new InvoiceTextParser;
        $analysis = $parser->parseWithReview(implode("\n", [
            'Tankstelle West',
            'Rechnungsdatum: 29.10.2026',
            'Gesamtbetrag: 75,00 EUR',
            '45,5 Liter',
            'Preis pro Liter: 1,6480',
            'Kilometerstand: 81234 km',
        ]), 95);

        $this->assertSame('45.5', $analysis['suggestions']['fuel_liters']);
        $this->assertSame('1.6480', $analysis['suggestions']['fuel_unit_price']);
        $this->assertSame('81234', $analysis['suggestions']['fuel_odometer_value']);
        $this->assertSame(['merchant', 'invoice_number', 'tax_amount'], $analysis['review_required']);
        $this->assertSame('missing', $analysis['field_status']['invoice_number']);
        $this->assertSame('missing', $analysis['field_status']['tax_amount']);
    }

    public function test_low_source_confidence_marks_extracted_fields_as_uncertain(): void
    {
        $analysis = (new InvoiceTextParser)->parseWithReview("Werkstatt Nord\nGesamtbetrag: 25,00 EUR", 40);

        $this->assertSame('uncertain', $analysis['field_status']['amount']);
        $this->assertContains('amount', $analysis['review_required']);
        $this->assertContains('merchant', $analysis['review_required']);
        $this->assertContains('invoice_date', $analysis['review_required']);
        $this->assertContains('currency', $analysis['review_required']);
        $this->assertContains('invoice_number', $analysis['review_required']);
        $this->assertContains('fuel_liters', $analysis['review_required']);
    }
}
