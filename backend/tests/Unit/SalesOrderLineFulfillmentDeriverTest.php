<?php

namespace Tests\Unit;

use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use PHPUnit\Framework\TestCase;

class SalesOrderLineFulfillmentDeriverTest extends TestCase
{
    public function test_derives_backorders_imported_status(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'     => ['value' => 'SKU-1'],
            'OrderQty'        => ['value' => 10],
            'ShippedQty'      => ['value' => 4],
            'QtyOnShipments'  => ['value' => 4],
            'OpenQty'         => ['value' => 6],
            'UnitPrice'       => ['value' => 100],
        ]);

        $this->assertSame('Backorders Imported', $mapped['fulfillment_status']);
        $this->assertSame(6.0, $mapped['backorder_qty']);
        $this->assertSame(40.0, $mapped['fill_rate_pct']);
        $this->assertSame(4.0, $mapped['qty_on_shipments']);
        $this->assertSame('qty_on_shipments', $mapped['qty_on_shipments_source']);
    }

    public function test_fill_rate_uses_order_qty_not_qty_at_approval(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'       => ['value' => 'SKU-2'],
            'OrderQty'          => ['value' => 100],
            'UsrQtyAtApproval'  => ['value' => 50],
            'ShippedQty'        => ['value' => 25],
            'QtyOnShipments'    => ['value' => 25],
            'OpenQty'           => ['value' => 25],
        ]);

        // Approval qty is still stored for reference, but fill rate is shipped/order.
        $this->assertSame(50.0, $mapped['qty_at_approval']);
        $this->assertSame(75.0, $mapped['backorder_qty']); // 100 − 25
        $this->assertSame(25.0, $mapped['fill_rate_pct']); // 25 / 100
    }

    public function test_qty_on_shipments_zero_marks_out_of_stock_reason(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'    => ['value' => 'FAYWP0024'],
            'OrderQty'       => ['value' => 10],
            'QtyOnShipments' => ['value' => 0],
            'UnitPrice'      => ['value' => 1706.90],
        ]);

        $this->assertSame(0.0, $mapped['qty_on_shipments']);
        $this->assertSame(0.0, $mapped['fill_rate_pct']);
        $this->assertSame('out_of_stock_procurement', $mapped['unfilled_reason_code']);
    }

    public function test_falls_back_to_shipped_qty_when_qty_on_shipments_missing(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID' => ['value' => 'SKU-LEGACY'],
            'OrderQty'    => ['value' => 10],
            'ShippedQty'  => ['value' => 7],
        ]);

        $this->assertSame(7.0, $mapped['qty_on_shipments']);
        $this->assertSame('shipped_qty_fallback', $mapped['qty_on_shipments_source']);
        $this->assertSame(70.0, $mapped['fill_rate_pct']);
    }

    public function test_prefers_acumatica_reason_code_over_derived_out_of_stock(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'    => ['value' => 'SKU-3'],
            'OrderQty'       => ['value' => 8],
            'QtyOnShipments' => ['value' => 0],
            'ReasonCode'     => ['value' => 'SUPPLIER_DELAY'],
        ]);

        $this->assertSame('delay_in_delivery', $mapped['unfilled_reason_code']);
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