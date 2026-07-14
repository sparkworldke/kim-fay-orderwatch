<?php

namespace Tests\Feature;

use App\Services\Operations\SalesOrderReasonCatalog;
use Tests\TestCase;

class SalesOrderReasonCatalogTest extends TestCase
{
    private SalesOrderReasonCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new SalesOrderReasonCatalog();
    }

    public function test_has_all_33_required_sub_reasons(): void
    {
        $this->assertCount(33, SalesOrderReasonCatalog::SUB_REASONS);
    }

    public function test_formats_hierarchical_label(): void
    {
        $label = $this->catalog->formatHierarchical(
            SalesOrderReasonCatalog::PARENT_CANCELLED_ORDER,
            'wrong_code',
        );

        $this->assertSame('Cancelled Order - Wrong code', $label);
    }

    public function test_maps_acumatica_aliases_to_canonical_codes(): void
    {
        $this->assertSame('short_expiry', $this->catalog->resolveSubReason('SHORT EXPIRY'));
        $this->assertSame('price_difference', $this->catalog->resolveSubReason('PRICE DIFFERENCE'));
        $this->assertSame('did_not_pick_on_shipment', $this->catalog->resolveSubReason('LOST BY DRIVER'));
        $this->assertSame('wrong_code', $this->catalog->resolveSubReason('WRONG PRICE'));
    }

    public function test_parent_for_status_maps_workflow_contexts(): void
    {
        $this->assertSame(SalesOrderReasonCatalog::PARENT_CANCELLED_ORDER, $this->catalog->parentForStatus('Canceled'));
        $this->assertSame(SalesOrderReasonCatalog::PARENT_REJECTED_ORDER, $this->catalog->parentForStatus('Rejected'));
        $this->assertSame(SalesOrderReasonCatalog::PARENT_ON_HOLD_ORDER, $this->catalog->parentForStatus('On Hold'));
    }

    public function test_classifies_missing_and_unclassified(): void
    {
        $missing = $this->catalog->classify(SalesOrderReasonCatalog::PARENT_REJECTED_ORDER, null);
        $this->assertSame(SalesOrderReasonCatalog::ISSUE_MISSING, $missing['issue']);

        $unclassified = $this->catalog->classify(SalesOrderReasonCatalog::PARENT_REJECTED_ORDER, 'UNKNOWN_CODE_XYZ');
        $this->assertSame(SalesOrderReasonCatalog::ISSUE_UNCLASSIFIED, $unclassified['issue']);
    }
}