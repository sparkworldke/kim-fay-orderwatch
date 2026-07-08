<?php

namespace Tests\Feature;

use App\Models\DailyReportConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyReportRoutingFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_config_returns_send_to_and_cc_split(): void
    {
        DailyReportConfig::singleton()->update([
            'reply_to_json' => ['primary1@kimfay.test', 'primary2@kimfay.test'],
            'recipients_json' => ['primary1@kimfay.test', 'primary2@kimfay.test', 'cc1@kimfay.test', 'cc2@kimfay.test'],
        ]);

        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/daily-reports/config')
            ->assertOk()
            ->assertJsonPath('send_to.0', 'primary1@kimfay.test')
            ->assertJsonPath('cc.0', 'cc1@kimfay.test');
    }
}

