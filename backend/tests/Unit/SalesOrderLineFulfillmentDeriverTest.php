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
        // Backorder/missing qty prefers OpenQty (25), not order−shipped (75).
        $this->assertSame(25.0, $mapped['backorder_qty']);
        $this->assertSame(25.0, $mapped['fill_rate_pct']); // 25 / 100
    }

    public function test_backorder_value_is_open_qty_times_unit_price_not_invoice_total(): void
    {
        // Real-world bug pattern: document total 570k, but only 24 CS open @ 460 = 11,040.
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'    => ['value' => '1100021002'],
            'OrderQty'       => ['value' => 1239.13043478],
            'ShippedQty'     => ['value' => 0],
            'OpenQty'        => ['value' => 24],
            'UnitPrice'      => ['value' => 460],
            'ExtendedPrice'  => ['value' => 570000],
            'UOM'            => ['value' => 'CS'],
        ]);

        $this->assertSame(24.0, $mapped['open_qty']);
        $this->assertSame(24.0, $mapped['backorder_qty']);
        $this->assertSame(460.0, $mapped['unit_price']);
        $this->assertSame(
            11040.0,
            SalesOrderLineFulfillmentDeriver::openLineValue($mapped['backorder_qty'], $mapped['unit_price']),
        );
        // Must not equal invoice / extended total.
        $this->assertNotEquals(570000.0, SalesOrderLineFulfillmentDeriver::openLineValue($mapped['backorder_qty'], $mapped['unit_price']));
    }

    public function test_residual_open_qty_does_not_double_subtract_qty_on_shipments(): void
    {
        // Shipped 3 of 6; OpenQty residual is 3. QtyOnShipments also 3.
        // Bug: open_recalc (6-3=3) − qty_on_shipments (3) = 0 → understated BO value.
        $this->assertSame(
            3.0,
            SalesOrderLineFulfillmentDeriver::residualOpenQty(6, 3, 3, 0, 3.0),
        );
        $this->assertSame(
            3.0,
            SalesOrderLineFulfillmentDeriver::residualOpenQty(6, 3, 3, 0, null),
        );
        $this->assertSame(3.0, SalesOrderLineFulfillmentDeriver::deliveredQty(0, 3));
        $this->assertSame(3.0, SalesOrderLineFulfillmentDeriver::deliveredQty(3, 3));
    }

    public function test_prefers_cury_unit_price_and_extended_price_fields(): void
    {
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'    => ['value' => 'SKU-CURY'],
            'OrderQty'       => ['value' => 10],
            'OpenQty'        => ['value' => 10],
            'CuryUnitPrice'  => ['value' => 99.5],
            'ExtendedPrice'  => ['value' => 995],
        ]);

        $this->assertSame(99.5, $mapped['unit_price']);
        $this->assertSame(995.0, $mapped['ext_cost']);
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

    public function test_explicit_open_qty_zero_with_qty_on_shipments_is_fully_fulfilled(): void
    {
        // SO359099 pattern: IpayV2 omits ShippedQty, OpenQty=0, QtyOnShipments=full order.
        // Must NOT invent open_qty = order − 0 = full line (570k false backorder).
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'    => ['value' => 'FAYMU0004'],
            'OrderQty'       => ['value' => 150],
            'OpenQty'        => ['value' => 0],
            'QtyOnShipments' => ['value' => 150],
            'Completed'      => ['value' => true],
            'UnitPrice'      => ['value' => 1900],
            'ExtendedPrice'  => ['value' => 285000],
            'UOM'            => ['value' => 'CASE'],
        ]);

        $this->assertSame(0.0, $mapped['open_qty']);
        $this->assertSame(0.0, $mapped['backorder_qty']);
        $this->assertSame(150.0, $mapped['shipped_qty']);
        $this->assertSame(150.0, $mapped['qty_on_shipments']);
        $this->assertSame('Fully Fulfilled', $mapped['fulfillment_status']);
        $this->assertSame(100.0, $mapped['fill_rate_pct']);
        $this->assertFalse(
            SalesOrderLineFulfillmentDeriver::isBackorderLine(
                $mapped['fulfillment_status'],
                $mapped['open_qty'],
                $mapped['backorder_qty'],
            )
        );
        $this->assertSame(
            0.0,
            SalesOrderLineFulfillmentDeriver::openLineValue($mapped['backorder_qty'], $mapped['unit_price']),
        );
    }

    public function test_partial_delivery_open_qty_times_price_not_order_total(): void
    {
        // Partial ship: 150 ordered, 144 shipped, 6 open @ 1900 = 11,400 (~11k).
        $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw([
            'InventoryID'    => ['value' => 'FAYMU0004'],
            'OrderQty'       => ['value' => 150],
            'OpenQty'        => ['value' => 6],
            'QtyOnShipments' => ['value' => 144],
            'Completed'      => ['value' => false],
            'UnitPrice'      => ['value' => 1900],
            'ExtendedPrice'  => ['value' => 285000],
        ]);

        $this->assertSame(6.0, $mapped['open_qty']);
        $this->assertSame(6.0, $mapped['backorder_qty']);
        $this->assertSame(144.0, $mapped['shipped_qty']);
        $this->assertSame(
            11400.0,
            SalesOrderLineFulfillmentDeriver::openLineValue($mapped['backorder_qty'], $mapped['unit_price']),
        );
        $this->assertTrue(
            SalesOrderLineFulfillmentDeriver::isBackorderLine(
                $mapped['fulfillment_status'],
                $mapped['open_qty'],
                $mapped['backorder_qty'],
            )
        );
    }
}