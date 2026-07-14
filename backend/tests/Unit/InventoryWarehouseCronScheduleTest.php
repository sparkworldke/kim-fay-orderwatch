<?php

namespace Tests\Unit;

use App\Models\CronJob;
use Tests\TestCase;

class InventoryWarehouseCronScheduleTest extends TestCase
{
    public function test_warehouse_stock_sync_is_staggered_by_thirty_minutes_from_830_and_1200(): void
    {
        config([
            'inventory.warehouses' => ['DTC', 'FGS', 'FGS2', 'FGS2 RETURNS', 'MSA', 'EXPORT', 'PRMS', 'RMS1', 'TRMS'],
            'inventory.stock_sync.morning_start' => '08:30',
            'inventory.stock_sync.midday_start' => '12:00',
            'inventory.stock_sync.stagger_minutes' => 30,
        ]);

        $expected = [
            0 => ['30 8 * * *', '0 12 * * *'],   // DTC 08:30, 12:00
            1 => ['0 9 * * *', '30 12 * * *'],    // FGS 09:00, 12:30
            2 => ['30 9 * * *', '0 13 * * *'],    // FGS2 09:30, 13:00
            3 => ['0 10 * * *', '30 13 * * *'],   // FGS2 RETURNS 10:00, 13:30
            4 => ['30 10 * * *', '0 14 * * *'],   // MSA 10:30, 14:00
            5 => ['0 11 * * *', '30 14 * * *'],   // EXPORT 11:00, 14:30
        ];

        foreach ($expected as $index => $crons) {
            $slots = CronJob::warehouseStockSyncCronExpressions($index);
            $this->assertSame($crons[0], $slots[0]['cron'], "morning slot for index {$index}");
            $this->assertSame($crons[1], $slots[1]['cron'], "midday slot for index {$index}");
        }

        $this->assertSame(['08:30', '12:00'], CronJob::warehouseStockSyncTimeLabels(0));
        $this->assertSame(['10:30', '14:00'], CronJob::warehouseStockSyncTimeLabels(4));
        $this->assertSame('inventory-sync-fgs2-returns', CronJob::inventoryWarehouseJobKey('FGS2 RETURNS'));
        $this->assertSame('FGS2 Returns', CronJob::inventoryWarehouseLabel('FGS2 RETURNS'));
    }
}
