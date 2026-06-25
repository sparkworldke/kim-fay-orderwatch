<?php

namespace Tests\Unit;

use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use PHPUnit\Framework\TestCase;

class SalesOrderLineFulfillmentDeriverTest extends TestCase
{
    public function test_derives_backorders_imported_status(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID' => ['value' => 'SKU-1'],
            'OrderQty'    => ['value' => 10],
            'ShippedQty'  => ['value' => 4],
            'OpenQty'     => ['value' => 6],
            'UnitPrice'   => ['value' => 100],
        ]);

        $this->assertSame('Backorders Imported', $mapped['fulfillment_status']);
        $this->assertSame(6.0, $mapped['backorder_qty']);
        $this->assertSame(40.0, $mapped['fill_rate_pct']);
    }

    public function test_uses_usr_qty_at_approval_for_fill_rate(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'       => ['value' => 'SKU-2'],
            'OrderQty'          => ['value' => 100],
            'UsrQtyAtApproval'  => ['value' => 50],
            'ShippedQty'        => ['value' => 25],
            'OpenQty'           => ['value' => 25],
        ]);

        $this->assertSame(50.0, $mapped['qty_at_approval']);
        $this->assertSame(25.0, $mapped['backorder_qty']);
        $this->assertSame(50.0, $mapped['fill_rate_pct']);
    }

    public function test_safe_fill_rate_returns_null_when_denominator_missing(): void
    {
        $this->assertNull(SalesOrderLineFulfillmentDeriver::safeFillRate(10, 0));
    }

    public function test_safe_fill_rate_caps_over_delivery_at_100(): void
    {
        $this->assertSame(100.0, SalesOrderLineFulfillmentDeriver::safeFillRate(12, 10));
    }

    public function test_derives_fully_fulfilled_when_completed(): void
    {
        $status = SalesOrderLineFulfillmentDeriver::deriveLineStatus(10, 10, 0, 0, true);
        $this->assertSame('Fully Fulfilled', $status);
    }

    public function test_identifies_backorder_lines(): void
    {
        $this->assertTrue(
            SalesOrderLineFulfillmentDeriver::isBackorderLine('Backorders Imported', 5)
        );
        $this->assertFalse(
            SalesOrderLineFulfillmentDeriver::isBackorderLine('Fully Fulfilled', 0)
        );
    }

    public function test_derives_open_qty_when_acumatica_omits_open_qty_field(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID' => ['value' => 'SKU-3'],
            'OrderQty'    => ['value' => 10],
            'ShippedQty'  => ['value' => 4],
            'UnitPrice'   => ['value' => 50],
        ]);

        $this->assertSame(6.0, $mapped['open_qty']);
        $this->assertSame('Backorders Imported', $mapped['fulfillment_status']);
        $this->assertTrue(
            SalesOrderLineFulfillmentDeriver::isBackorderLine(
                $mapped['fulfillment_status'],
                $mapped['open_qty'],
                $mapped['backorder_qty'],
            )
        );
    }
}