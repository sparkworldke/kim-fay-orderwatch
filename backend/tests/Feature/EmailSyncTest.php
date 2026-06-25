<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\EmailFilter;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\MailboxSyncLog;
use App\Models\MailboxSyncItemLog;
use App\Models\OrderMatchSyncRun;
use App\Models\User;
use App\Services\Email\OutlookEmailService;
use App\Services\OrderMatch\OrderMatchFolderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
    }

    private function regularUser(): User
    {
        return User::factory()->create([
            'role' => 'Viewer',
            'is_active' => true,
        ]);
    }

    private function mailbox(): MailboxAccount
    {
        return MailboxAccount::create([
            'email' => 'inbox@example.com',
            'display_name' => 'Test Inbox',
            'access_token_encrypted' => 'encrypted-access-token',
            'refresh_token_encrypted' => 'encrypted-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
    }

    private function singleCondition(string $type, string $value): array
    {
        return [[
            'type' => $type,
            'value' => $value,
        ]];
    }

    // --- Auth ---

    public function test_unauthenticated_user_cannot_list_emails(): void
    {
        $this->getJson('/api/emails')->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_list_mailboxes(): void
    {
        $this->getJson('/api/admin/mailboxes')->assertUnauthorized();
    }

    // --- Mailbox management (admin only) ---

    public function test_admin_can_list_connected_mailboxes(): void
    {
        $this->mailbox();

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('/api/admin/mailboxes')
            ->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertEquals('inbox@example.com', $response->json('0.email'));
    }

    public function test_non_admin_cannot_list_mailboxes(): void
    {
        $this->actingAs($this->regularUser(), 'sanctum')
            ->getJson('/api/admin/mailboxes')
            ->assertForbidden();
    }

    public function test_admin_can_delete_a_mailbox(): void
    {
        $mailbox = $this->mailbox();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->deleteJson("/api/admin/mailboxes/{$mailbox->id}")
            ->assertOk();

        $this->assertDatabaseMissing('mailbox_accounts', ['id' => $mailbox->id]);
    }

    public function test_admin_can_update_a_mailbox_with_put(): void
    {
        $mailbox = $this->mailbox();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson("/api/admin/mailboxes/{$mailbox->id}", [
                'sync_from_date' => '2026-06-01',
            ])
            ->assertOk()
            ->assertJsonFragment(['sync_from_date' => '2026-06-01']);

        $this->assertDatabaseHas('mailbox_accounts', [
            'id' => $mailbox->id,
            'sync_from_date' => '2026-06-01 00:00:00',
            'delta_token' => null,
        ]);
    }

    public function test_admin_can_update_a_mailbox_with_patch(): void
    {
        $mailbox = $this->mailbox();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->patchJson("/api/admin/mailboxes/{$mailbox->id}", [
                'sync_from_date' => '2026-06-15',
            ])
            ->assertOk()
            ->assertJsonFragment(['sync_from_date' => '2026-06-15']);
    }

    public function test_sync_endpoint_runs_immediately_without_queue_worker(): void
    {
        $mailbox = $this->mailbox();
        $this->mock(OutlookEmailService::class, function ($mock) use ($mailbox) {
            $mock->shouldReceive('syncEmails')
                ->once()
                ->withArgs(fn (MailboxAccount $account, ?int $filterId, MailboxSyncLog $syncLog) => $account->is($mailbox)
                    && $filterId === null
                    && $syncLog->mailbox_account_id === $mailbox->id)
                ->andReturn(3);
        });

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/admin/mailboxes/{$mailbox->id}/sync")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Sync started. Emails will be imported in the background.']);

        $log = MailboxSyncLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($mailbox->id, $log->mailbox_account_id);
        $this->assertSame('completed', $log->status);
        $this->assertSame(3, $log->emails_fetched);
        $this->assertNull($log->email_filter_id);
    }

    public function test_rule_sync_records_filter_identity(): void
    {
        $mailbox = $this->mailbox();
        $filter = EmailFilter::create([
            'name' => 'Naivas PO rule',
            'conditions' => $this->singleCondition('sender_domain', 'naivas.net'),
            'is_active' => true,
        ]);
        $this->mock(OutlookEmailService::class, function ($mock) use ($mailbox, $filter) {
            $mock->shouldReceive('syncEmails')
                ->once()
                ->withArgs(fn (MailboxAccount $account, ?int $filterId, MailboxSyncLog $syncLog) => $account->is($mailbox)
                    && $filterId === $filter->id
                    && $syncLog->email_filter_id === $filter->id)
                ->andReturn(0);
        });

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/email-filters/{$filter->id}/sync")
            ->assertOk();

        $this->assertDatabaseHas('mailbox_sync_logs', [
            'mailbox_account_id' => $mailbox->id,
            'email_filter_id' => $filter->id,
            'status' => 'completed',
        ]);
    }

    public function test_sync_logs_return_privacy_safe_aggregated_skip_reasons(): void
    {
        $mailbox = $this->mailbox();
        $filter = EmailFilter::create([
            'name' => 'Naivas PO rule',
            'conditions' => $this->singleCondition('sender_domain', 'naivas.net'),
            'is_active' => true,
        ]);
        $ruleRun = MailboxSyncLog::create([
            'mailbox_account_id' => $mailbox->id,
            'email_filter_id' => $filter->id,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'emails_fetched' => 5,
            'emails_skipped' => 5,
            'status' => 'completed',
        ]);
        foreach ([
            ['filter_not_matched', 'private-message-1'],
            ['filter_not_matched', 'private-message-2'],
            ['filter_not_matched', 'private-message-3'],
            ['unchanged', 'private-message-4'],
            ['unchanged', 'private-message-5'],
        ] as [$reason, $messageId]) {
            MailboxSyncItemLog::create([
                'mailbox_sync_log_id' => $ruleRun->id,
                'message_id' => $messageId,
                'outcome' => 'skipped',
                'reason' => $reason,
                'attempts' => 1,
                'duration_ms' => 1,
                'processed_at' => now(),
            ]);
        }
        $historicalRun = MailboxSyncLog::create([
            'mailbox_account_id' => $mailbox->id,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'emails_fetched' => 1,
            'emails_skipped' => 1,
            'status' => 'completed',
        ]);
        MailboxSyncItemLog::create([
            'mailbox_sync_log_id' => $historicalRun->id,
            'message_id' => 'private-message-6',
            'outcome' => 'skipped',
            'reason' => 'filter_not_matched',
            'attempts' => 1,
            'duration_ms' => 1,
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("/api/admin/mailboxes/{$mailbox->id}/sync-logs")
            ->assertOk();

        $runs = collect($response->json())->keyBy('id');
        $this->assertSame('rule', $runs[$ruleRun->id]['sync_scope']['type']);
        $this->assertSame('Naivas PO rule', $runs[$ruleRun->id]['sync_scope']['filter_name']);
        $this->assertEqualsCanonicalizing([
            ['code' => 'filter_not_matched', 'label' => 'Does not match "Naivas PO rule"', 'count' => 3],
            ['code' => 'unchanged', 'label' => 'Already imported; no changes', 'count' => 2],
        ], $runs[$ruleRun->id]['reason_counts']);
        $this->assertSame('all', $runs[$historicalRun->id]['sync_scope']['type']);
        $this->assertSame('Does not match the selected email rule', $runs[$historicalRun->id]['reason_counts'][0]['label']);
        $this->assertStringNotContainsString('private-message', $response->getContent());
        $this->assertArrayNotHasKey('item_logs', $runs[$ruleRun->id]);
    }

    // --- Email listing ---

    public function test_authenticated_user_can_list_emails(): void
    {
        $mailbox = $this->mailbox();

        Email::create([
            'mailbox_account_id' => $mailbox->id,
            'message_id' => 'msg-001',
            'subject' => 'Hello World',
            'from_email' => 'sender@test.com',
            'from_name' => 'Sender',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->regularUser(), 'sanctum')
            ->getJson('/api/emails')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_emails_can_be_filtered_by_mailbox(): void
    {
        $mailbox1 = $this->mailbox();
        $mailbox2 = MailboxAccount::create([
            'email' => 'other@example.com',
            'display_name' => 'Other',
            'access_token_encrypted' => 'tok',
            'refresh_token_encrypted' => 'ref',
            'status' => 'connected',
        ]);

        Email::create(['mailbox_account_id' => $mailbox1->id, 'message_id' => 'msg-a', 'subject' => 'A', 'from_email' => 'a@a.com', 'received_at' => now()]);
        Email::create(['mailbox_account_id' => $mailbox2->id, 'message_id' => 'msg-b', 'subject' => 'B', 'from_email' => 'b@b.com', 'received_at' => now()]);

        $response = $this->actingAs($this->regularUser(), 'sanctum')
            ->getJson("/api/emails?mailbox_id={$mailbox1->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('A', $response->json('data.0.subject'));
    }

    public function test_emails_can_be_searched_by_subject(): void
    {
        $mailbox = $this->mailbox();
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'm1', 'subject' => 'Invoice 2024', 'from_email' => 'x@x.com', 'received_at' => now()]);
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'm2', 'subject' => 'Meeting notes', 'from_email' => 'y@y.com', 'received_at' => now()]);

        $response = $this->actingAs($this->regularUser(), 'sanctum')
            ->getJson('/api/emails?search=invoice')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsStringIgnoringCase('invoice', $response->json('data.0.subject'));
    }

    // --- Email filters CRUD ---

    public function test_user_can_create_an_email_filter(): void
    {
        $this->actingAs($this->regularUser(), 'sanctum')
            ->postJson('/api/email-filters', [
                'name' => 'Gmail Domain',
                'conditions' => $this->singleCondition('sender_domain', 'gmail.com'),
            ])
            ->assertCreated()
            ->assertJsonFragment([
                'name' => 'Gmail Domain',
                'type' => 'sender_domain',
                'value' => 'gmail.com',
                'match_count' => 0,
            ]);

        $this->assertDatabaseHas('email_filters', ['name' => 'Gmail Domain']);
    }

    public function test_user_can_create_an_email_filter_with_legacy_single_condition_payload(): void
    {
        $this->actingAs($this->regularUser(), 'sanctum')
            ->postJson('/api/email-filters', [
                'name' => 'Legacy Gmail Domain',
                'type' => 'sender_domain',
                'value' => 'gmail.com',
            ])
            ->assertCreated()
            ->assertJsonFragment([
                'name' => 'Legacy Gmail Domain',
                'type' => 'sender_domain',
                'value' => 'gmail.com',
            ]);
    }

    public function test_email_filter_match_count_reflects_stored_emails(): void
    {
        $mailbox = $this->mailbox();
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'x1', 'subject' => 'Hi', 'from_email' => 'alice@gmail.com', 'received_at' => now()]);
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'x2', 'subject' => 'Hi', 'from_email' => 'bob@yahoo.com',  'received_at' => now()]);

        $response = $this->actingAs($this->regularUser(), 'sanctum')
            ->postJson('/api/email-filters', [
                'name' => 'Gmail',
                'conditions' => $this->singleCondition('sender_domain', 'gmail.com'),
            ])
            ->assertCreated();

        $this->assertEquals(1, $response->json('match_count'));
    }

    public function test_user_can_update_a_filter(): void
    {
        $filter = EmailFilter::create([
            'name' => 'Old',
            'conditions' => $this->singleCondition('sender_email', 'old@old.com'),
            'is_active' => true,
        ]);

        $this->actingAs($this->regularUser(), 'sanctum')
            ->patchJson("/api/email-filters/{$filter->id}", ['name' => 'New Name', 'is_active' => false])
            ->assertOk()
            ->assertJsonFragment(['name' => 'New Name', 'is_active' => false]);
    }

    public function test_user_can_delete_a_filter(): void
    {
        $filter = EmailFilter::create([
            'name' => 'To Delete',
            'conditions' => $this->singleCondition('sender_email', 'd@d.com'),
            'is_active' => true,
        ]);

        $this->actingAs($this->regularUser(), 'sanctum')
            ->deleteJson("/api/email-filters/{$filter->id}")
            ->assertOk();

        $this->assertDatabaseMissing('email_filters', ['id' => $filter->id]);
    }

    public function test_filter_validation_rejects_invalid_type(): void
    {
        $this->actingAs($this->regularUser(), 'sanctum')
            ->postJson('/api/email-filters', [
                'name' => 'Bad',
                'conditions' => $this->singleCondition('invalid_type', 'whatever'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.0.type']);
    }

    public function test_filter_list_returns_match_counts_for_all_filters(): void
    {
        $mailbox = $this->mailbox();
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'y1', 'subject' => 'Order shipped', 'from_email' => 'shop@store.com', 'received_at' => now()]);

        EmailFilter::create([
            'name' => 'Store',
            'conditions' => $this->singleCondition('sender_domain', 'store.com'),
            'is_active' => true,
        ]);
        EmailFilter::create([
            'name' => 'Orders',
            'conditions' => $this->singleCondition('subject_keyword', 'order'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->regularUser(), 'sanctum')
            ->getJson('/api/email-filters')
            ->assertOk();

        $filters = collect($response->json())->keyBy('name');
        $this->assertEquals(1, $filters['Store']['match_count']);
        $this->assertEquals(1, $filters['Orders']['match_count']);
    }

    public function test_admin_can_sync_mailbox_folder_by_date_range(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $mailbox = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $mailbox->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'is_sync_enabled' => true,
            'is_order_folder' => true,
            'trust_level' => 'trusted_order',
        ]);

        $this->mock(OrderMatchFolderSyncService::class, function ($mock) use ($folder) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (MailboxFolder $target, string $from, string $to) => $target->is($folder)
                    && $from === '2026-06-24'
                    && $to === '2026-06-24')
                ->andReturn(OrderMatchSyncRun::create([
                    'mailbox_folder_id' => $folder->id,
                    'sync_from' => '2026-06-24',
                    'sync_to' => '2026-06-24',
                    'status' => 'processing',
                    'started_at' => now(),
                ]));
        });

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/admin/mailbox-folders/{$folder->id}/sync", [
                'from' => '2026-06-24',
                'to' => '2026-06-24',
            ])
            ->assertAccepted()
            ->assertJsonFragment([
                'folder_name' => 'Naivas POs',
                'status' => 'processing',
            ]);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SyncMailboxFolderJob::class);
    }

    public function test_admin_can_sync_mailbox_folder_with_datetime_bounds(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $mailbox = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $mailbox->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'is_sync_enabled' => true,
            'trust_level' => 'trusted_order',
        ]);

        $this->mock(OrderMatchFolderSyncService::class, function ($mock) use ($folder) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (MailboxFolder $target, string $from, string $to) => $target->is($folder)
                    && $from === '2026-06-24T08:00'
                    && $to === '2026-06-24T18:30')
                ->andReturn(OrderMatchSyncRun::create([
                    'mailbox_folder_id' => $folder->id,
                    'sync_from' => '2026-06-24',
                    'sync_to' => '2026-06-24',
                    'status' => 'processing',
                    'started_at' => now(),
                ]));
        });

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/admin/mailbox-folders/{$folder->id}/sync", [
                'from' => '2026-06-24T08:00',
                'to' => '2026-06-24T18:30',
            ])
            ->assertAccepted()
            ->assertJsonFragment(['status' => 'processing']);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SyncMailboxFolderJob::class);
    }

    public function test_mailbox_folder_sync_rejected_when_sync_disabled(): void
    {
        $mailbox = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $mailbox->id,
            'external_folder_id' => 'archive-id',
            'display_name' => 'Archive',
            'is_sync_enabled' => false,
            'trust_level' => 'untrusted',
        ]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/admin/mailbox-folders/{$folder->id}/sync", [
                'from' => '2026-06-24',
                'to' => '2026-06-24',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Enable sync for this folder before running a manual sync.']);
    }

    // --- OAuth start ---

    public function test_admin_can_start_oauth_flow(): void
    {
        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/admin/mailboxes/oauth/start')
            ->assertOk();

        $this->assertArrayHasKey('auth_url', $response->json());
        $this->assertStringContainsString('login.microsoftonline.com', $response->json('auth_url'));
    }

    public function test_admin_can_run_oauth_diagnostics_with_post(): void
    {
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'client-secret',
            'services.microsoft.tenant_id' => 'tenant-id',
            'services.microsoft.redirect_uri' => 'https://example.com/oauth/callback',
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*/v2.0/.well-known/openid-configuration' => Http::response([], 200),
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'The probe code is intentionally invalid.',
            ], 400),
        ]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/admin/mailboxes/oauth/check')
            ->assertOk()
            ->assertJsonPath('overall_ok', true)
            ->assertJsonPath('checks.app_registration.ok', true)
            ->assertJsonStructure(['checks', 'mailbox_tokens', 'checked_at']);
    }

    public function test_oauth_callback_preserves_existing_mailbox_and_displays_graph_email(): void
    {
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'client-secret',
            'services.microsoft.tenant_id' => 'tenant-id',
            'services.microsoft.redirect_uri' => 'https://example.com/oauth/callback',
        ]);

        $existing = $this->mailbox();

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/me*' => Http::response([
                'displayName' => 'Order Watch',
                'mail' => strtoupper($existing->email),
            ]),
        ]);

        $account = app(OutlookEmailService::class)->handleCallback('valid-code');

        $this->assertSame($existing->id, $account->id);
        $this->assertSame($existing->email, $account->email);
        $this->assertSame('Order Watch', $account->display_name);
        $this->assertSame('connected', $account->status);
        $this->assertDatabaseCount('mailbox_accounts', 1);
    }
}
