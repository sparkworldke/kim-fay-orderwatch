<?php

namespace Tests\Unit;

use App\Models\AcumaticaSalesOrder;
use App\Services\Email\SupportingFieldComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportingFieldComparatorTest extends TestCase
{
    use RefreshDatabase;

    private SupportingFieldComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comparator = new SupportingFieldComparator;
    }

    public function test_naivas_total_matches_when_email_ex_vat_plus_vat_is_within_tolerance(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-VAT',
            'customer_name' => 'Naivas Supermarket',
            'customer_order' => '42562296',
            'order_total' => 106348.55,
            'currency_id' => 'KES',
            'order_date' => now(),
            'status' => 'Open',
        ]);

        $conflicts = $this->comparator->compare(
            $order,
            'Currency: KES Total: 92971.49',
            'notification@naivas.net',
            'Naivas Supermarket',
        );

        $this->assertSame([], $conflicts);
    }

    public function test_naivas_total_conflict_includes_vat_adjusted_email_amount(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-VAT-2',
            'customer_name' => 'Naivas Supermarket',
            'customer_order' => '42562297',
            'order_total' => 120000.00,
            'currency_id' => 'KES',
            'order_date' => now(),
            'status' => 'Open',
        ]);

        $conflicts = $this->comparator->compare(
            $order,
            'Currency: KES Total: 92971.49',
            'notification@naivas.net',
            'Naivas Supermarket',
        );

        $this->assertCount(1, $conflicts);
        $this->assertSame('total', $conflicts[0]['field']);
        $this->assertSame('92971.49', $conflicts[0]['email_value']);
        $this->assertSame('120000.00', $conflicts[0]['acumatica_value']);
        $this->assertSame('107846.93', $conflicts[0]['email_value_inc_vat']);
        $this->assertSame('16', $conflicts[0]['vat_rate']);
        $this->assertSame('naivas_vat_adjusted_conflict', $conflicts[0]['reason']);
        $this->assertSame('12153.07', $conflicts[0]['amount_delta']);
    }

    public function test_naivas_pdf_order_total_matches_acumatica_without_conflict(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-PDF',
            'customer_name' => 'Naivas Supermarket',
            'customer_order' => '42568464',
            'order_total' => 171587.71,
            'currency_id' => 'KES',
            'order_date' => now(),
            'status' => 'Open',
        ]);

        $parser = new \App\Services\Email\PartnerPoPdfContextService;
        $text = $parser->buildSearchableText(file_get_contents(base_path('../changes/naivas-po.pdf')));
        $conflicts = $this->comparator->compare(
            $order,
            $text,
            'notification@naivas.net',
            'Naivas Supermarket',
        );

        $this->assertSame([], $conflicts);
    }

    public function test_non_naivas_total_does_not_apply_vat_uplift(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-GEN',
            'customer_name' => 'Generic Buyer',
            'customer_order' => '004512',
            'order_total' => 106348.55,
            'currency_id' => 'KES',
            'order_date' => now(),
            'status' => 'Open',
        ]);

        $conflicts = $this->comparator->compare(
            $order,
            'Currency: KES Total: 92971.49',
            'orders@buyer.example',
            'Generic Buyer',
        );

        $this->assertCount(1, $conflicts);
        $this->assertSame('explicit_value_conflict', $conflicts[0]['reason']);
        $this->assertArrayNotHasKey('email_value_inc_vat', $conflicts[0]);
    }
}