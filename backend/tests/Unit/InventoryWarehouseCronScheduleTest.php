<?php

namespace Tests\Unit;

use App\Models\CronJob;
use Tests\TestCase;

class InventoryWarehouseCronScheduleTest extends TestCase
{
    public function test_business_checkpoints_and_staggered_job_offsets(): void
    {
        $this->assertSame(
            ['0 8 * * *', '30 10 * * *', '0 13 * * *', '0 15 * * *', '20 16 * * *'],
            CronJob::businessCheckpointCronExpressions(),
        );
        $this->assertSame(
            ['5 8 * * *', '35 10 * * *', '5 13 * * *', '5 15 * * *', '25 16 * * *'],
            CronJob::businessCheckpointCronExpressions(5),
        );
    }

    public function test_each_warehouse_runs_once_daily_distributed_across_business_checkpoints(): void
    {
        config([
            'inventory.warehouses' => ['DTC', 'FGS', 'FGS2', 'FGS2 RETURNS', 'MSA', 'EXPORT', 'PRMS', 'RMS1', 'TRMS'],
            'inventory.stock_sync.morning_start' => '08:30',
            'inventory.stock_sync.midday_start' => '12:00',
            'inventory.stock_sync.stagger_minutes' => 30,
        ]);

        $expected = [
            0 => '0 8 * * *',
            1 => '30 10 * * *',
            2 => '0 13 * * *',
            3 => '0 15 * * *',
            4 => '20 16 * * *',
            5 => '0 8 * * *',
        ];

        foreach ($expected as $index => $cron) {
            $slots = CronJob::warehouseStockSyncCronExpressions($index);
            $this->assertCount(1, $slots);
            $this->assertSame($cron, $slots[0]['cron'], "daily slot for index {$index}");
        }

        $this->assertSame(['08:00'], CronJob::warehouseStockSyncTimeLabels(0));
        $this->assertSame(['16:20'], CronJob::warehouseStockSyncTimeLabels(4));
        $this->assertSame('inventory-sync-fgs2-returns', CronJob::inventoryWarehouseJobKey('FGS2 RETURNS'));
        $this->assertSame('FGS2 Returns', CronJob::inventoryWarehouseLabel('FGS2 RETURNS'));
    }

    public function test_tpfgs_is_configured_for_stock_sync_and_manual_import(): void
    {
        $warehouses = CronJob::inventoryWarehouses();
        $this->assertContains('TPFGS', $warehouses);

        $index = array_search('TPFGS', $warehouses, true);
        $this->assertIsInt($index);

        $this->assertSame(
            ['16:20'],
            CronJob::warehouseStockSyncTimeLabels($index),
        );
        $this->assertSame('inventory-sync-tpfgs', CronJob::inventoryWarehouseJobKey('TPFGS'));
        $this->assertSame('TPFGS (Tatu Park FG)', CronJob::inventoryWarehouseLabel('TPFGS'));
    }
}
