<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SignInLog;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserActivityExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_include_actor_names(): void
    {
        $admin = User::factory()->create([
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'role' => 'Administrator',
        ]);
        Sanctum::actingAs($admin);

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'timestamp' => now(),
            'actor_user_id' => $admin->id,
            'actor_ip' => '127.0.0.1',
            'action_type' => 'team_member_created',
            'resource_type' => 'user',
            'resource_id' => '99',
            'changes' => [],
        ]);

        $this->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.actor_name', 'Ada Admin')
            ->assertJsonPath('data.0.actor_email', 'ada@example.com')
            ->assertJsonPath('data.0.actor_label', 'Ada Admin <ada@example.com>');
    }

    public function test_page_view_activity_is_recorded(): void
    {
        $user = User::factory()->create(['role' => 'Sales Consultant']);
        Sanctum::actingAs($user);

        $this->postJson('/api/activity/page-view', [
            'path' => '/app/orders',
            'page_title' => 'Orders',
        ])->assertCreated();

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'activity_type' => 'page_view',
            'path' => '/app/orders',
            'page_title' => 'Orders',
        ]);

        // Dedup within 10 seconds
        $this->postJson('/api/activity/page-view', [
            'path' => '/app/orders',
            'page_title' => 'Orders',
        ])->assertOk()->assertJsonPath('deduped', true);

        $this->assertSame(1, UserActivityLog::where('user_id', $user->id)->count());
    }

    public function test_export_excel_has_login_and_activity_content_type(): void
    {
        $admin = User::factory()->create([
            'name' => 'Export Admin',
            'email' => 'export@example.com',
            'role' => 'Administrator',
        ]);
        Sanctum::actingAs($admin);

        SignInLog::create([
            'user_id' => $admin->id,
            'email_hash' => hash('sha256', $admin->email),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PHPUnit',
            'login_mode' => 'password',
            'status' => 'success',
            'created_at' => now(),
        ]);
        UserSession::create([
            'user_id' => $admin->id,
            'login_at' => now()->subHour(),
            'login_mode' => 'password',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
        UserActivityLog::create([
            'user_id' => $admin->id,
            'activity_type' => 'page_view',
            'path' => '/app/production',
            'page_title' => 'Manufactured Intel',
            'ip_address' => '10.0.0.1',
        ]);

        $this->get('/api/admin/audit-logs/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
