<?php

namespace Tests\Feature;

use App\Models\AcumaticaSyncLog;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\MailboxAccount;
use App\Models\OrderMatchRun;
use App\Models\User;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Cron\HourlyAutoMatchCronService;
use App\Services\Email\OrderMatchingService;
use App\Services\Email\OutlookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CronEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_job_creates_visible_skipped_run(): void
    {
        $job = CronJob::hourlyAutoMatch();
        $job->update(['is_enabled' => false, 'status' => 'paused']);

        $run = $this->service()->run($job);

        $this->assertSame('skipped', $run->status);
        $this->assertStringContainsString('disabled', $run->error_summary);
    }

    public function test_overlapping_job_creates_visible_skipped_run(): void
    {
        $job = CronJob::hourlyAutoMatch();
        $lock = Cache::lock('cron-job:'.$job->job_key, 60);
        $this->assertTrue($lock->get());
        try {
            $run = $this->service()->run($job);
            $this->assertSame('skipped', $run->status);
            $this->assertStringContainsString('still active', $run->error_summary);
        } finally {
            $lock->release();
        }
    }

    public function test_successful_pipeline_correlates_mailbox_and_match_logs(): void
    {
        $job = CronJob::hourlyAutoMatch();
        $job->update(['settings' => array_merge($job->settings, ['acumatica_sync_enabled' => false])]);
        MailboxAccount::create([
            'email' => 'cron@example.com', 'access_token_encrypted' => 'token',
            'refresh_token_encrypted' => 'refresh', 'status' => 'connected',
        ]);
        $outlook = Mockery::mock(OutlookEmailService::class);
        $outlook->shouldReceive('syncEmails')->once()->andReturn(0);
        $matching = Mockery::mock(OrderMatchingService::class);
        $matching->shouldReceive('runPoExtraction')->once()->andReturn(['processed' => 0, 'extracted' => 0]);
        $matching->shouldReceive('runOrderMatching')->once()->andReturnUsing(function ($userId, $cronId) {
            return OrderMatchRun::create([
                'cron_run_log_id' => $cronId, 'started_at' => now(), 'ended_at' => now(),
                'status' => 'completed', 'emails_processed' => 0,
            ]);
        });

        $run = $this->service($outlook, matching: $matching)->run($job, 'manual');

        $this->assertSame('success', $run->status);
        $this->assertDatabaseHas('mailbox_sync_logs', ['cron_run_log_id' => $run->id, 'status' => 'completed']);
        $this->assertDatabaseHas('order_match_runs', ['cron_run_log_id' => $run->id, 'status' => 'completed']);
    }

    public function test_acumatica_failure_with_successful_matching_is_partial(): void
    {
        $job = CronJob::hourlyAutoMatch();
        $job->update(['settings' => array_merge($job->settings, ['email_sync_enabled' => false])]);
        $acumatica = Mockery::mock(AcumaticaSalesOrderSyncService::class);
        $acumatica->shouldReceive('syncDateRange')->once()->andReturnUsing(function ($from, $to, $user, $source, $cronId) {
            return AcumaticaSyncLog::create([
                'cron_run_log_id' => $cronId, 'sync_type' => 'sales_orders', 'started_at' => now(),
                'ended_at' => now(), 'status' => 'failed', 'record_count' => 0,
                'success_count' => 0, 'failed_count' => 0, 'error_message' => 'Acumatica unavailable',
                'trigger_type' => $source,
            ]);
        });
        $matching = Mockery::mock(OrderMatchingService::class);
        $matching->shouldReceive('runPoExtraction')->andReturn(['processed' => 0, 'extracted' => 0]);
        $matching->shouldReceive('runOrderMatching')->andReturnUsing(fn ($user, $cronId) => OrderMatchRun::create([
            'cron_run_log_id' => $cronId, 'started_at' => now(), 'ended_at' => now(),
            'status' => 'completed', 'emails_processed' => 0,
        ]));

        $run = $this->service(acumatica: $acumatica, matching: $matching)->run($job);

        $this->assertSame('partial', $run->status);
        $this->assertSame('failed', $run->step_status['acumatica_sync']['status']);
        $this->assertSame('success', $run->step_status['matching']['status']);
    }

    public function test_cron_api_is_admin_only_and_never_exposes_secrets(): void
    {
        $viewer = User::factory()->create(['role' => 'Viewer', 'is_active' => true]);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/admin/cron-jobs')->assertForbidden();

        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/cron-jobs')->assertOk();
        $this->assertStringNotContainsString('token', strtolower($response->getContent()));
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
    }

    private function service($outlook = null, $acumatica = null, $matching = null): HourlyAutoMatchCronService
    {
        return new HourlyAutoMatchCronService(
            $outlook ?: Mockery::mock(OutlookEmailService::class),
            $acumatica ?: Mockery::mock(AcumaticaSalesOrderSyncService::class),
            $matching ?: Mockery::mock(OrderMatchingService::class),
        );
    }
}
