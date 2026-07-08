<?php

namespace Tests\Unit;

use App\Models\AcumaticaSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcumaticaSyncLogGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_long_running_marks_stale_order_sync_as_failed(): void
    {
        AcumaticaSyncLog::create([
            'sync_type' => 'sales_orders',
            'started_at' => now()->subHours(3),
            'heartbeat_at' => now()->subMinute(),
            'status' => 'running',
            'record_count' => 10,
            'success_count' => 5,
            'failed_count' => 0,
            'trigger_type' => 'scheduler',
        ]);

        $updated = AcumaticaSyncLog::failLongRunning(['sales_orders']);

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('acumatica_sync_logs', [
            'sync_type' => 'sales_orders',
            'status' => 'failed',
        ]);
    }
}