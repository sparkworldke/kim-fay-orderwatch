<?php

namespace Tests\Unit;

use App\Services\OrderMatch\CustomerPoMatchResolver;
use PHPUnit\Framework\TestCase;

class CustomerPoMatchResolverTest extends TestCase
{
    private CustomerPoMatchResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CustomerPoMatchResolver;
    }

    public function test_naivas_strips_p0_prefix_for_customer_order_id(): void
    {
        $this->assertSame('42562296', $this->resolver->naivasCustomerOrderId('P042562296'));
        $this->assertSame(
            '42562296',
            $this->resolver->toCustomerOrderId('P042562296', 'notification@naivas.net'),
        );
        $this->assertSame(['42562296'], $this->resolver->acumaticaLookupKeys('P042562296', 'notification@naivas.net'));
        $this->assertSame('42562296', $this->resolver->toCanonicalMatchKey('P042562296', 'notification@naivas.net'));
    }

    public function test_naivas_normalises_messy_acumatica_and_subject_formats(): void
    {
        $this->assertSame('42566300', $this->resolver->naivasCustomerOrderId('PO : 42566300'));
        $this->assertSame('42566300', $this->resolver->naivasCustomerOrderId('P-042566300'));
        $this->assertSame(
            '42574206',
            $this->resolver->naivasCustomerOrderIdFromSubject('RE: FWD: PO : #P042574206!!!'),
        );
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical('PO : 42566300', '42566300'));
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical('42566300', '42566300'));
    }

    public function test_carrefour_uses_subject_digits_as_customer_order_id(): void
    {
        $subject = 'C4 GCM XGCM     26021220';

        $this->assertSame('26021220', $this->resolver->carrefourDigitsFromSubject($subject));
        $this->assertSame(
            '26021220',
            $this->resolver->toCustomerOrderId('26021220', 'KENCarrefourOrders@maf.ae', null, $subject),
        );
        $this->assertSame(
            ['26021220'],
            $this->resolver->acumaticaLookupKeys('26021220', 'kencarrefourorders@maf.ae', null, $subject),
        );
    }

    public function test_carrefour_requires_subject_digits_to_match_extracted_po(): void
    {
        $reasons = $this->resolver->validateEvidence(
            '26021220',
            [['source' => 'subject', 'raw_match' => '26021220']],
            'kencarrefourorders@maf.ae',
            null,
            'C4 GCM XGCM     26021220',
        );

        $this->assertSame([], $reasons);
    }

    public function test_carrefour_rejects_when_subject_digits_missing(): void
    {
        $reasons = $this->resolver->validateEvidence(
            '26021220',
            [['source' => 'body', 'raw_match' => '26021220']],
            'kencarrefourorders@maf.ae',
        );

        $this->assertContains('carrefour_subject_digits_missing', $reasons);
    }

    public function test_carrefour_normalises_messy_acumatica_and_subject_formats(): void
    {
        $this->assertSame('2600050', $this->resolver->carrefourCustomerOrderId('C4 KEV 2600050'));
        $this->assertSame('2600050', $this->resolver->carrefourCustomerOrderId('C4-KEV-2600050'));
        $this->assertSame('26016501', $this->resolver->carrefourDigitsFromSubject('C4 KEI MALL 26016501'));
        $this->assertSame('2600050', $this->resolver->carrefourDigitsFromSubject('RE: FWD: C4 #KEV@ 2600050!'));
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical(
            'C4 KEV-2600050',
            '2600050',
            'kencarrefourorders@maf.ae',
        ));
        $this->assertFalse($this->resolver->customerOrderMatchesCanonical(
            'C4 KEV 2600050',
            '42600050',
            'kencarrefourorders@maf.ae',
        ));
    }

    public function test_carrefour_and_naivas_sanitise_plain_and_po_prefix_formats(): void
    {
        $this->assertSame('26018406', $this->resolver->carrefourCustomerOrderId('26018406'));
        $this->assertSame('26013623', $this->resolver->carrefourCustomerOrderId('po : 26013623'));
        $this->assertSame('26018406', $this->resolver->naivasCustomerOrderId('26018406'));
        $this->assertSame('26013623', $this->resolver->naivasCustomerOrderId('po : 26013623'));
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical(
            'po : 26013623',
            '26013623',
            'kencarrefourorders@maf.ae',
        ));
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical(
            '26018406',
            '26018406',
            'notification@naivas.net',
        ));
    }

    public function test_naivas_accepts_attachment_filename_evidence(): void
    {
        $reasons = $this->resolver->validateEvidence('P042562296', [
            ['source' => 'attachment_filename:12', 'raw_match' => 'P042562296.pdf'],
        ], 'notification@naivas.net');

        $this->assertSame([], $reasons);
    }

    public function test_quickmart_lookup_keys_include_suffix_digits(): void
    {
        $keys = $this->resolver->acumaticaLookupKeys(
            '074-00002048',
            'orders@joska.quickmart.co.ke',
        );

        $this->assertContains('074-00002048', $keys);
        $this->assertContains('2048', $keys);
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical(
            'PO : 2048',
            '074-00002048',
            'orders@joska.quickmart.co.ke',
        ));
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical(
            '82814',
            '009-00082814',
            'orders@joska.quickmart.co.ke',
        ));
    }

    public function test_chandarana_lookup_keys_include_suffix_digits(): void
    {
        $keys = $this->resolver->acumaticaLookupKeys(
            '1001120070924',
            'orders@chandaranasupermarkets.co.ke',
        );

        $this->assertContains('1001120070924', $keys);
        $this->assertContains('70924', $keys);
        $this->assertSame(
            '1001120070924',
            $this->resolver->toCustomerOrderId('1001120070924', 'orders@chandaranasupermarkets.co.ke'),
        );
        $this->assertTrue($this->resolver->customerOrderMatchesCanonical(
            'PO : 70924',
            '70924',
            'orders@chandaranasupermarkets.co.ke',
        ));
    }
}