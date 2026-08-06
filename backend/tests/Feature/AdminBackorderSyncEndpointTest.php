<?php

namespace Tests\Feature;

use App\Models\AcumaticaSyncLog;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AdminBackorderSyncEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sync_backorders_runs_synchronously_and_returns_final_status(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')
            ->once()
            ->andReturn([]);
        $this->app->instance(AcumaticaClient::class, $client);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/acumatica/sync/backorders', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-23',
            ]);

        $response->assertOk();
        // No defer/queue involved — the response only comes back once the sync has settled.
        $this->assertNotSame('running', $response->json('sync_run.status'));
        $this->assertDatabaseHas('acumatica_sync_logs', [
            'id' => $response->json('sync_run.id'),
        ]);
        $log = AcumaticaSyncLog::find($response->json('sync_run.id'));
        $this->assertNotSame('running', $log->status);
    }

    public function test_sync_logs_endpoint_self_heals_a_stale_running_row(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        // Simulate the exact stuck-job scenario: started this morning, heartbeat never advanced
        // because whatever executed it (a dropped defer callback, a killed worker) never finished.
        Carbon::setTestNow(Carbon::parse('2026-07-23 09:02:03', 'Africa/Nairobi'));
        $stale = AcumaticaSyncLog::create([
            'sync_type' => 'backorders',
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
            'record_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'trigger_type' => 'manual',
            'filters' => ['date_from' => '2026-07-01', 'date_to' => '2026-07-23'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-23 14:00:00', 'Africa/Nairobi'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/acumatica/sync/logs')
            ->assertOk();

        $stale->refresh();
        $this->assertSame('failed', $stale->status);
    }
}
