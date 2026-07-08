<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\MailboxAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailImportConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_branch_sender_requires_dual_admin_approval(): void
    {
        $creator = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $approver = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $customer = AcumaticaCustomer::create([
            'acumatica_id' => 'CHANDARA-001',
            'name' => 'Chandara Main',
            'is_main_account' => true,
        ]);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/admin/email-import-configs', [
                'sender_pattern' => 'bangkok-branch@chandara.com',
                'match_mode' => 'exact',
                'display_name' => 'Bangkok Branch',
                'customer_id' => $customer->id,
                'branch_name' => 'Bangkok Branch',
            ])
            ->assertCreated();

        $configId = $response->json('id');

        $this->assertDatabaseHas('email_import_configs', [
            'id' => $configId,
            'approval_status' => 'pending',
            'is_active' => false,
        ]);

        $this->actingAs($creator, 'sanctum')
            ->postJson("/api/admin/email-import-configs/{$configId}/approve")
            ->assertStatus(422);

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/admin/email-import-configs/{$configId}/approve")
            ->assertOk()
            ->assertJsonPath('config.approval_status', 'approved');

        $this->assertDatabaseHas('email_import_configs', [
            'id' => $configId,
            'approval_status' => 'approved',
            'approved_by' => $approver->id,
            'is_active' => true,
        ]);
    }

    public function test_unsafe_regex_patterns_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/email-import-configs', [
                'sender_pattern' => '/.+@.+/',
                'match_mode' => 'regex',
                'display_name' => 'Unsafe regex',
            ])
            ->assertStatus(422);
    }

    public function test_metrics_endpoint_reports_import_health(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $mailbox = MailboxAccount::create([
            'email' => 'metrics@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);

        Email::create([
            'mailbox_account_id' => $mailbox->id,
            'message_id' => 'metric-1',
            'subject' => 'PO 1001',
            'from_email' => 'branch@chandara.com',
            'folder' => 'Inbox',
            'received_at' => now()->subHours(2),
            'ingestion_classification' => 'po_processing',
            'import_guardrail_status' => 'matched',
        ]);
        Email::create([
            'mailbox_account_id' => $mailbox->id,
            'message_id' => 'metric-2',
            'subject' => 'Unknown sender',
            'from_email' => 'unknown@example.com',
            'folder' => 'Inbox',
            'received_at' => now()->subHours(1),
            'ingestion_classification' => 'stored_non_order',
            'import_guardrail_status' => 'unrecognized',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/email-import-configs/metrics')
            ->assertOk()
            ->assertJsonPath('imported_orders_last_24h', 1)
            ->assertJsonPath('unrecognized_emails_last_24h', 1);
    }
}
