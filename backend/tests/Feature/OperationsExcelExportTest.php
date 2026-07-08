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
            'reason_code' => 'inventory_shortage',
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
            ->get('/api/operations/backorders/export?reason_code=inventory_shortage');

        $response->assertOk();
        $this->assertStringContainsString('backorders-export-', $response->headers->get('content-disposition'));
        $workbook = $this->workbookFromResponse($response);

        $this->assertSame('Backorders', $workbook->getSheet(0)->getTitle());
        $this->assertSame('SO-KEEP', $workbook->getSheetByName('Backorders')->getCell('A2')->getValue());
        $this->assertSame('inventory_shortage', $workbook->getSheetByName('Backorders')->getCell('V2')->getValue());
        $this->assertSame('Inventory Shortage', $workbook->getSheetByName('Backorders')->getCell('W2')->getValue());
        $this->assertSame(null, $workbook->getSheetByName('Backorders')->getCell('A3')->getValue());
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
            'unfilled_reason_code' => 'supplier_delay',
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-FR',
            'customer_acumatica_id' => 'CUST-1',
            'status' => 'Open',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 2,
            'fill_rate_pct' => 20,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 400,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/operations/fill-rate/export?date_from=2026-07-01&date_to=2026-07-31&reason_code=supplier_delay');

        $response->assertOk();
        $workbook = $this->workbookFromResponse($response);

        $this->assertSame('SO-FR', $workbook->getSheetByName('Fill Rate')->getCell('A2')->getValue());
        $this->assertSame('Product Lines', $workbook->getSheetByName('Product Lines')->getTitle());
        $this->assertSame('supplier_delay', $workbook->getSheetByName('Product Lines')->getCell('M2')->getValue());
        $this->assertSame('Supplier Delay', $workbook->getSheetByName('Product Lines')->getCell('N2')->getValue());
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
