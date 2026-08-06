<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaShippingZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class OperationsExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_backorders_export_returns_filtered_xlsx_with_reasons(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-KEEP',
            'description' => 'Keep Product',
            'item_class' => 'Care',
            'qty_on_hand' => 2,
            'qty_available' => 1,
            'synced_at' => now(),
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-SKIP',
            'description' => 'Skip Product',
            'item_class' => 'Other',
            'qty_on_hand' => 20,
            'synced_at' => now(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-KEEP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-1',
            'customer_name' => 'Keep Customer',
            'order_date' => '2026-07-06',
            'sales_consultant_rep_code' => 'P505',
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SKIP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-2',
            'customer_name' => 'Skip Customer',
            'order_date' => '2026-07-06',
            'sales_consultant_rep_code' => 'P777',
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-KEEP',
            'inventory_id' => 'SKU-KEEP',
            'customer_acumatica_id' => 'CUST-1',
            'customer_name' => 'Keep Customer',
            'order_qty' => 10,
            'shipped_qty' => 4,
            'open_qty' => 6,
            'backorder_qty' => 6,
            'cancelled_qty' => 0,
            'unit_price' => 100,
            'revenue_at_risk' => 600,
            'warehouse_id' => 'FGS',
            'reason_code' => 'out_of_stock_procurement',
            'reason_notes' => 'No stock',
            'synced_at' => now(),
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-SKIP',
            'inventory_id' => 'SKU-SKIP',
            'customer_acumatica_id' => 'CUST-2',
            'customer_name' => 'Skip Customer',
            'open_qty' => 5,
            'backorder_qty' => 5,
            'revenue_at_risk' => 500,
            'warehouse_id' => 'FGS',
            'reason_code' => null,
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/backorders/export?reason_code=out_of_stock_procurement&date_from=2026-07-01&date_to=2026-07-31');

        $response->assertOk();
        $this->assertStringContainsString('backorders-export-', $response->headers->get('content-disposition'));
        $workbook = $this->workbookFromResponse($response);

        // Multi-sheet structure mirrors Fill Rate export (Instructions + Summary + detail).
        $sheetNames = [];
        foreach ($workbook->getAllSheets() as $s) {
            $sheetNames[] = $s->getTitle();
        }
        $this->assertContains('Start Here', $sheetNames);
        $this->assertContains('Summary', $sheetNames);
        $this->assertContains('Backorders', $sheetNames);
        $this->assertContains('Manufactured Lines', $sheetNames);
        $this->assertContains('Trading (Partners) Lines', $sheetNames);
        $this->assertContains('Exposure by SKU', $sheetNames);
        $this->assertContains('Reason Summary', $sheetNames);
        $this->assertContains('Customer Summary', $sheetNames);
        $this->assertContains('Product Summary', $sheetNames);
        $this->assertContains('Orders with Backorders', $sheetNames);
        $this->assertContains('Missing Price Values', $sheetNames);

        $this->assertSame('Start Here', $workbook->getSheet(0)->getTitle());

        $sheet = $workbook->getSheetByName('Backorders');
        $this->assertNotNull($sheet);
        $this->assertSame('SO-KEEP', $sheet->getCell('A2')->getValue());

        // Locate columns by header (layout can grow).
        $reasonCodeCol = null;
        $reasonLabelCol = null;
        $coverageCol = null;
        $ownerCol = null;
        $actionCol = null;
        for ($col = 1; $col <= 40; $col++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $header = (string) $sheet->getCell("{$letter}1")->getValue();
            if ($header === 'Reason Code') {
                $reasonCodeCol = $letter;
            }
            if ($header === 'Reason') {
                $reasonLabelCol = $letter;
            }
            if ($header === 'Stock Coverage') {
                $coverageCol = $letter;
            }
            if ($header === 'Owner (who acts)') {
                $ownerCol = $letter;
            }
            if ($header === 'Next Action') {
                $actionCol = $letter;
            }
        }
        $this->assertNotNull($reasonCodeCol, 'Reason Code column missing');
        $this->assertNotNull($reasonLabelCol, 'Reason column missing');
        $this->assertNotNull($coverageCol, 'Stock Coverage column missing');
        $this->assertNotNull($ownerCol, 'Owner column missing');
        $this->assertNotNull($actionCol, 'Next Action column missing');
        $this->assertSame('out_of_stock_procurement', $sheet->getCell("{$reasonCodeCol}2")->getValue());
        $this->assertSame('Out of stock - Procurement', $sheet->getCell("{$reasonLabelCol}2")->getValue());
        $this->assertContains($sheet->getCell("{$coverageCol}2")->getValue(), ['Full', 'Partial', 'None', 'N/A']);
        $this->assertNotSame('', (string) $sheet->getCell("{$ownerCol}2")->getValue());
        $this->assertNotSame('', (string) $sheet->getCell("{$actionCol}2")->getValue());
        $this->assertSame(null, $sheet->getCell('A3')->getValue());

        $summary = $workbook->getSheetByName('Summary');
        $this->assertNotNull($summary);
        $this->assertStringContainsString('Decision Summary', (string) $summary->getCell('A1')->getValue());
    }

    public function test_fill_rate_export_includes_product_line_reasons(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaShippingZone::create([
            'acumatica_id' => 'NAIROBI',
            'description' => 'Nairobi',
            'name' => 'Nairobi',
            'region' => 'nairobi',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-1',
            'name' => 'Keep Customer',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-FR',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-1',
            'customer_name' => 'Keep Customer',
            'order_date' => '2026-07-06',
            'approved_at' => '2026-07-06 08:00:00',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-FR',
            'description' => 'Fill Product',
            'order_qty' => 10,
            'shipped_qty' => 2,
            'qty_on_shipments' => 0,
            'open_qty' => 8,
            'unit_price' => 50,
            'uom' => 'EA',
            'fill_rate_pct' => 20,
            'unfilled_reason_code' => 'delay_in_delivery',
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-FR',
            'customer_acumatica_id' => 'CUST-1',
            'status' => 'Completed',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 2,
            'fill_rate_pct' => 20,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 400,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-07-01&date_to=2026-07-31&reason_code=delay_in_delivery');

        $response->assertOk();
        $workbook = $this->workbookFromResponse($response);

        $this->assertSame('SO-FR', $workbook->getSheetByName('Fill Rate')->getCell('A2')->getValue());
        $this->assertSame('Product Lines', $workbook->getSheetByName('Product Lines')->getTitle());
        $this->assertSame('delay_in_delivery', $workbook->getSheetByName('Product Lines')->getCell('M2')->getValue());
        $this->assertSame('Delay in delivery', $workbook->getSheetByName('Product Lines')->getCell('N2')->getValue());
    }

    /**
     * The "SOs Not Fully Delivered" sheet should list every order whose fill
     * rate is below 100% (excluding NA orders), sorted by value shortfall
     * descending.  Fully delivered orders should NOT appear.
     */
    public function test_fill_rate_export_includes_sos_not_fully_delivered_sheet(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaShippingZone::create([
            'acumatica_id' => 'NAIROBI',
            'description' => 'Nairobi',
            'name' => 'Nairobi',
            'region' => 'nairobi',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-1',
            'name' => 'Incomplete Customer',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-2',
            'name' => 'Complete Customer',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);

        // ── Incomplete order (50% fill rate, 400 revenue shortfall) ──
        $incomplete = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-INCOMPLETE',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-1',
            'customer_name' => 'Incomplete Customer',
            'order_date' => '2026-07-06',
            'approved_at' => '2026-07-06 08:00:00',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $incomplete->id,
            'inventory_id' => 'SKU-A',
            'description' => 'Product A',
            'order_qty' => 10,
            'shipped_qty' => 5,
            'qty_on_shipments' => 5,
            'qty_at_approval' => 10,
            'open_qty' => 5,
            'unit_price' => 80,
            'uom' => 'EA',
            'fill_rate_pct' => 50,
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $incomplete->id,
            'order_nbr' => 'SO-INCOMPLETE',
            'customer_acumatica_id' => 'CUST-1',
            'status' => 'Completed',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 5,
            'fill_rate_pct' => 50,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 400,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        // ── Fully delivered order (100% fill rate, 0 shortfall) ──
        $complete = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-COMPLETE',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-2',
            'customer_name' => 'Complete Customer',
            'order_date' => '2026-07-06',
            'approved_at' => '2026-07-06 08:00:00',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $complete->id,
            'inventory_id' => 'SKU-B',
            'description' => 'Product B',
            'order_qty' => 8,
            'shipped_qty' => 8,
            'qty_on_shipments' => 8,
            'qty_at_approval' => 8,
            'open_qty' => 0,
            'unit_price' => 60,
            'uom' => 'EA',
            'fill_rate_pct' => 100,
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $complete->id,
            'order_nbr' => 'SO-COMPLETE',
            'customer_acumatica_id' => 'CUST-2',
            'status' => 'Completed',
            'total_ordered_qty' => 8,
            'total_shipped_qty' => 8,
            'fill_rate_pct' => 100,
            'fill_rate_status' => 'good',
            'revenue_not_shipped' => 0,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-07-01&date_to=2026-07-31&include_out_of_stock=1');

        $response->assertOk();
        $workbook = $this->workbookFromResponse($response);
        $sheet = $workbook->getSheetByName('SOs Not Fully Delivered');

        $this->assertNotNull($sheet);
        $this->assertSame('SO-INCOMPLETE', $sheet->getCell('A2')->getValue());
        $this->assertSame('CUST-1', $sheet->getCell('B2')->getValue());
        $this->assertSame(10.0, $sheet->getCell('F2')->getValue());   // Ordered Qty
        $this->assertSame(5.0, $sheet->getCell('G2')->getValue());    // Shipped Qty
        $this->assertSame(5.0, $sheet->getCell('H2')->getValue());    // Unfilled Qty
        $this->assertSame(400.0, $sheet->getCell('J2')->getValue());  // Value Shortfall

        // The fully-delivered order should NOT appear in row 3.
        $this->assertNotSame('SO-COMPLETE', $sheet->getCell('A3')->getValue());
    }

    /**
     * The "Missing Price Values" sheet should flag every product line whose
     * unit price is zero or null.  Lines with valid prices must be excluded.
     */
    public function test_fill_rate_export_includes_missing_price_sheet(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaShippingZone::create([
            'acumatica_id' => 'NAIROBI',
            'description' => 'Nairobi',
            'name' => 'Nairobi',
            'region' => 'nairobi',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-MP',
            'name' => 'Missing Price Customer',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-MP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-MP',
            'customer_name' => 'Missing Price Customer',
            'order_date' => '2026-07-06',
            'approved_at' => '2026-07-06 08:00:00',
        ]);

        // Line WITH a price — should NOT appear in the Missing Price sheet.
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-PRICED',
            'description' => 'Priced Product',
            'order_qty' => 10,
            'shipped_qty' => 4,
            'qty_on_shipments' => 4,
            'qty_at_approval' => 10,
            'open_qty' => 6,
            'unit_price' => 50,
            'uom' => 'EA',
            'fill_rate_pct' => 40,
            'unfilled_reason_code' => 'delay_in_delivery',
        ]);

        // Line WITHOUT a price — SHOULD appear in the Missing Price sheet.
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-FREE',
            'description' => 'Free Product',
            'order_qty' => 20,
            'shipped_qty' => 0,
            'qty_on_shipments' => 0,
            'qty_at_approval' => 20,
            'open_qty' => 20,
            'unit_price' => 0,
            'uom' => 'EA',
            'fill_rate_pct' => 0,
            'unfilled_reason_code' => 'out_of_stock_procurement',
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-MP',
            'customer_acumatica_id' => 'CUST-MP',
            'status' => 'Completed',
            'total_ordered_qty' => 30,
            'total_shipped_qty' => 4,
            'fill_rate_pct' => 13,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 300,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-07-01&date_to=2026-07-31&include_out_of_stock=1');

        $response->assertOk();
        $workbook = $this->workbookFromResponse($response);
        $sheet = $workbook->getSheetByName('Missing Price Values');

        $this->assertNotNull($sheet);
        // Only the missing-price line should be flagged.
        $this->assertSame('SKU-FREE', $sheet->getCell('C2')->getValue());  // Inventory ID
        $this->assertSame('MISSING PRICE', $sheet->getCell('I2')->getValue()); // Flag
        // The priced line should NOT appear in row 3.
        $this->assertNotSame('SKU-PRICED', $sheet->getCell('C3')->getValue());
    }

    public function test_inventory_export_includes_latest_prediction_data(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $item = AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-INV',
            'description' => 'Inventory Product',
            'brand' => 'Fay',
            'product_type' => 'manufactured',
            'item_class' => 'Care',
            'default_warehouse_id' => 'FGS',
            'default_uom' => 'EA',
            'qty_on_hand' => 5,
            'qty_available' => 3,
            'sales_price' => 120,
            'synced_at' => now(),
        ]);
        AcumaticaInventoryRunRateLog::create([
            'inventory_item_id' => $item->id,
            'inventory_id' => 'SKU-INV',
            'qty_on_hand' => 5,
            'qty_delta' => -2,
            'daily_run_rate' => 2.5,
            'days_until_stockout' => 2,
            'prediction_status' => 'critical',
            'logged_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/inventory/export?low_stock=1&warehouse_id[]=FGS');

        $response->assertOk();
        $workbook = $this->workbookFromResponse($response);

        $this->assertSame('SKU-INV', $workbook->getSheetByName('Inventory')->getCell('A2')->getValue());
        $this->assertSame('critical', $workbook->getSheetByName('Inventory')->getCell('N2')->getValue());
        $this->assertSame('Risk Summary', $workbook->getSheetByName('Risk Summary')->getTitle());
        $this->assertSame('Warehouse Summary', $workbook->getSheetByName('Warehouse Summary')->getTitle());
    }

    public function test_fill_rate_export_summary_sheet_includes_kpi_tiles_and_period(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaShippingZone::create([
            'acumatica_id' => 'NAIROBI',
            'description' => 'Nairobi',
            'name' => 'Nairobi',
            'region' => 'nairobi',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-SUM',
            'name' => 'Summary Customer',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-SUM',
            'description' => 'Summary Product',
            'brand' => 'Fay',
            'product_type' => 'manufactured',
            'synced_at' => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SUM',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-SUM',
            'customer_name' => 'Summary Customer',
            'order_date' => '2026-07-06',
            'approved_at' => '2026-07-06 08:00:00',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-SUM',
            'description' => 'Summary Product',
            'order_qty' => 10,
            'shipped_qty' => 4,
            'qty_on_shipments' => 4,
            'qty_at_approval' => 10,
            'open_qty' => 6,
            'unit_price' => 50,
            'uom' => 'EA',
            'fill_rate_pct' => 40,
            'unfilled_reason_code' => 'delay_in_delivery',
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-SUM',
            'customer_acumatica_id' => 'CUST-SUM',
            'status' => 'Completed',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 4,
            'fill_rate_pct' => 40,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 300,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-07-01&date_to=2026-07-31');

        $response->assertOk();
        $sheet = $this->workbookFromResponse($response)->getSheetByName('Summary');

        $this->assertNotNull($sheet);
        $this->assertSame('Fill Rate — Lost Sales Summary', $sheet->getCell('A1')->getValue());
        $this->assertSame('Period: 2026-07-01 to 2026-07-31', $sheet->getCell('A2')->getValue());
        $this->assertSame('Total Lost Sales (KES)', $sheet->getCell('A4')->getValue());
        $this->assertSame('KES 300.00', $sheet->getCell('B4')->getValue());
        $this->assertSame('SOs Not Fully Delivered', $sheet->getCell('A7')->getValue());
        $this->assertSame('1 / 1 (100%)', $sheet->getCell('B7')->getValue());
        $this->assertSame('Revenue Shortfall (KES)', $sheet->getCell('A8')->getValue());
        $this->assertSame('KES 300.00', $sheet->getCell('B8')->getValue());
        $this->assertSame('SKUs Affected', $sheet->getCell('A10')->getValue());
        $this->assertEquals(1, $sheet->getCell('B10')->getValue());
    }

    public function test_fill_rate_period_uses_order_date_not_computed_at_for_summary_list_and_export(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-JUNE',
            'name' => 'June Customer',
            'synced_at' => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-JUNE-FR',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-JUNE',
            'customer_name' => 'June Customer',
            'status' => 'Completed',
            'order_date' => '2026-06-10 09:00:00',
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-JUNE',
            'description' => 'June Product',
            'order_qty' => 10,
            'shipped_qty' => 4,
            'qty_on_shipments' => 4,
            'open_qty' => 6,
            'unit_price' => 50,
            'uom' => 'EA',
            'fill_rate_pct' => 40,
            'unfilled_reason_code' => 'delay_in_delivery',
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-JUNE-FR',
            'customer_acumatica_id' => 'CUST-JUNE',
            'status' => 'Completed',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 4,
            'fill_rate_pct' => 40,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 300,
            'computed_at' => '2026-07-14 15:18:00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/fill-rate/summary?date_from=2026-06-01&date_to=2026-06-13&include_out_of_stock=1')
            ->assertOk()
            ->assertJsonPath('order_count', 1)
            ->assertJsonPath('overall_fill_rate', 40);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/fill-rate?date_from=2026-06-01&date_to=2026-06-13&include_out_of_stock=1')
            ->assertOk()
            ->assertJsonPath('data.0.order_nbr', 'SO-JUNE-FR');

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-06-01&date_to=2026-06-13&include_out_of_stock=1');

        $response->assertOk();
        $workbook = $this->workbookFromResponse($response);

        $this->assertSame('SO-JUNE-FR', $workbook->getSheetByName('Fill Rate')->getCell('A2')->getValue());
        $this->assertSame('SO-JUNE-FR', $workbook->getSheetByName('Product Lines')->getCell('A2')->getValue());
    }

    public function test_fill_rate_export_recomputes_include_out_of_stock_toggle(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-OOS-EXPORT',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-OOS',
            'customer_name' => 'OOS Customer',
            'status' => 'Completed',
            'order_date' => '2026-06-10',
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-OOS',
            'description' => 'Out of Stock Product',
            'order_qty' => 10,
            'shipped_qty' => 0,
            'qty_on_shipments' => 0,
            'open_qty' => 10,
            'unit_price' => 20,
            'fill_rate_pct' => 0,
            'unfilled_reason_code' => 'out_of_stock_procurement',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-SHIPPED',
            'description' => 'Delivered Product',
            'order_qty' => 10,
            'shipped_qty' => 10,
            'qty_on_shipments' => 10,
            'open_qty' => 0,
            'unit_price' => 20,
            'fill_rate_pct' => 100,
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-OOS-EXPORT',
            'customer_acumatica_id' => 'CUST-OOS',
            'status' => 'Completed',
            'total_ordered_qty' => 20,
            'total_shipped_qty' => 10,
            'fill_rate_pct' => 50,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 200,
            'computed_at' => '2026-07-14 15:18:00',
        ]);

        $excluded = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-06-01&date_to=2026-06-13&include_out_of_stock=0');
        $included = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-06-01&date_to=2026-06-13&include_out_of_stock=1');

        $excluded->assertOk();
        $included->assertOk();

        $excludedWorkbook = $this->workbookFromResponse($excluded);
        $includedWorkbook = $this->workbookFromResponse($included);
        $excludedSheet = $excludedWorkbook->getSheetByName('Fill Rate');
        $includedSheet = $includedWorkbook->getSheetByName('Fill Rate');

        $this->assertSame(10.0, $excludedSheet->getCell('F2')->getValue());
        $this->assertSame(10.0, $excludedSheet->getCell('G2')->getValue());
        $this->assertSame(100.0, $excludedSheet->getCell('H2')->getValue());
        $this->assertSame('healthy', $excludedSheet->getCell('I2')->getValue());
        $this->assertSame('SKU-SHIPPED', $excludedWorkbook->getSheetByName('Product Lines')->getCell('C2')->getValue());
        $this->assertSame(null, $excludedWorkbook->getSheetByName('Product Lines')->getCell('C3')->getValue());
        // Product Summary is InventoryID shortfall only — fully shipped lines contribute 0 and are omitted.
        $this->assertSame(null, $excludedWorkbook->getSheetByName('Product Summary')->getCell('A2')->getValue());

        $this->assertSame(20.0, $includedSheet->getCell('F2')->getValue());
        $this->assertSame(10.0, $includedSheet->getCell('G2')->getValue());
        $this->assertSame(50.0, $includedSheet->getCell('H2')->getValue());
        $this->assertSame('critical', $includedSheet->getCell('I2')->getValue());
        $this->assertSame('SKU-OOS', $includedWorkbook->getSheetByName('Product Summary')->getCell('A2')->getValue());
        $this->assertSame(200.0, $includedWorkbook->getSheetByName('Product Summary')->getCell('D2')->getValue());
    }

    public function test_backorders_product_summary_exports_all_inventory_ids_for_june_value_reconciliation(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $expectedTotal = 0.0;

        for ($i = 1; $i <= 12; $i++) {
            $inventoryId = sprintf('SKU-BO-%02d', $i);
            AcumaticaInventoryItem::create([
                'inventory_id' => $inventoryId,
                'description' => "Backorder Product {$i}",
                'synced_at' => now(),
            ]);
            AcumaticaSalesOrder::create([
                'acumatica_order_nbr' => sprintf('SO-BO-%02d', $i),
                'order_type' => 'SO',
                'customer_acumatica_id' => 'CUST-BO',
                'customer_name' => 'Backorder Customer',
                'order_date' => '2026-06-10',
            ]);

            $value = 9000000.0;
            $expectedTotal += $value;
            AcumaticaBackorderLine::create([
                'order_nbr' => sprintf('SO-BO-%02d', $i),
                'inventory_id' => $inventoryId,
                'customer_acumatica_id' => 'CUST-BO',
                'customer_name' => 'Backorder Customer',
                'open_qty' => 1,
                'backorder_qty' => 1,
                'revenue_at_risk' => $value,
                'synced_at' => '2026-07-14 15:18:00',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/backorders/export?date_from=2026-06-01&date_to=2026-06-30');

        $response->assertOk();
        $sheet = $this->workbookFromResponse($response)->getSheetByName('Product Summary');

        $this->assertSame('SKU-BO-01', $sheet->getCell('A2')->getValue());
        $this->assertSame('SKU-BO-12', $sheet->getCell('A13')->getValue());
        $this->assertSame(null, $sheet->getCell('A14')->getValue());

        $actualTotal = 0.0;
        for ($row = 2; $row <= 13; $row++) {
            $actualTotal += (float) $sheet->getCell("E{$row}")->getValue();
        }

        $this->assertGreaterThan(100000000, $actualTotal);
        $this->assertSame($expectedTotal, $actualTotal);
    }

    public function test_fill_rate_product_summary_exports_all_inventory_ids_grouped_by_inventory_id_for_june_orders(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-FR-JUNE-PRODUCTS',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-FR-PRODUCTS',
            'customer_name' => 'Fill Rate Customer',
            'status' => 'Completed',
            'order_date' => '2026-06-10',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            $inventoryId = sprintf('SKU-FR-%02d', $i);
            AcumaticaInventoryItem::create([
                'inventory_id' => $inventoryId,
                'description' => "Fill Rate Product {$i}",
                'synced_at' => now(),
            ]);
            AcumaticaSalesOrderLine::create([
                'sales_order_id' => $order->id,
                'inventory_id' => $inventoryId,
                'description' => "Fill Rate Product {$i}",
                'order_qty' => 1,
                'shipped_qty' => 0,
                'qty_on_shipments' => 0,
                'open_qty' => 1,
                'unit_price' => 9000000,
                'uom' => 'EA',
                'fill_rate_pct' => 0,
                'unfilled_reason_code' => 'delay_in_delivery',
            ]);
        }

        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU-FR-01',
            'description' => 'Fill Rate Product 1',
            'order_qty' => 1,
            'shipped_qty' => 0,
            'qty_on_shipments' => 0,
            'open_qty' => 1,
            'unit_price' => 1000000,
            'uom' => 'EA',
            'fill_rate_pct' => 0,
            'unfilled_reason_code' => 'delay_in_delivery',
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-FR-JUNE-PRODUCTS',
            'customer_acumatica_id' => 'CUST-FR-PRODUCTS',
            'status' => 'Completed',
            'total_ordered_qty' => 13,
            'total_shipped_qty' => 0,
            'fill_rate_pct' => 0,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 109000000,
            'computed_at' => '2026-07-14 15:18:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-06-01&date_to=2026-06-30&include_out_of_stock=1');

        $response->assertOk();
        $sheet = $this->workbookFromResponse($response)->getSheetByName('Product Summary');

        $this->assertSame('SKU-FR-01', $sheet->getCell('A2')->getValue());
        $this->assertSame(2, $sheet->getCell('C2')->getValue());
        $this->assertSame(10000000.0, $sheet->getCell('D2')->getValue());
        $this->assertSame('SKU-FR-12', $sheet->getCell('A13')->getValue());
        $this->assertSame(null, $sheet->getCell('A14')->getValue());

        $actualTotal = 0.0;
        for ($row = 2; $row <= 13; $row++) {
            $actualTotal += (float) $sheet->getCell("D{$row}")->getValue();
        }

        $this->assertGreaterThan(100000000, $actualTotal);
        $this->assertSame(109000000.0, $actualTotal);
    }

    public function test_sales_consultant_backorders_export_is_scoped_to_rep_code(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);
        foreach ([['SO-MINE', 'P505'], ['SO-OTHER', 'P777']] as [$orderNbr, $repCode]) {
            AcumaticaSalesOrder::create([
                'acumatica_order_nbr' => $orderNbr,
                'order_type' => 'SO',
                'customer_acumatica_id' => $orderNbr,
                'customer_name' => $orderNbr,
                'order_date' => '2026-07-06',
                'sales_consultant_rep_code' => $repCode,
            ]);
            AcumaticaBackorderLine::create([
                'order_nbr' => $orderNbr,
                'inventory_id' => 'SKU-'.$orderNbr,
                'customer_acumatica_id' => $orderNbr,
                'customer_name' => $orderNbr,
                'open_qty' => 1,
                'backorder_qty' => 1,
                'revenue_at_risk' => 100,
                'synced_at' => now(),
            ]);
        }

        $response = $this->actingAs($consultant, 'sanctum')
            ->get('/api/operations/backorders/export');

        $response->assertOk();
        $sheet = $this->workbookFromResponse($response)->getSheetByName('Backorders');

        $this->assertSame('SO-MINE', $sheet->getCell('A2')->getValue());
        $this->assertSame(null, $sheet->getCell('A3')->getValue());
    }

    private function workbookFromResponse($response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'orderwatch-export-').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $workbook = IOFactory::load($path);
        @unlink($path);

        return $workbook;
    }
}
