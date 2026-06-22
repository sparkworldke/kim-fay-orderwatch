<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\EmailFilter;
use App\Models\MailboxAccount;
use App\Models\MailboxSyncLog;
use App\Models\User;
use App\Services\Email\OutlookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create([
            'role'           => 'Administrator',
            'is_super_admin' => true,
            'is_active'      => true,
        ]);
    }

    private function regularUser(): User
    {
        return User::factory()->create([
            'role'      => 'Viewer',
            'is_active' => true,
        ]);
    }

    private function mailbox(): MailboxAccount
    {
        return MailboxAccount::create([
            'email'                    => 'inbox@example.com',
            'display_name'             => 'Test Inbox',
            'access_token_encrypted'   => 'encrypted-access-token',
            'refresh_token_encrypted'  => 'encrypted-refresh-token',
            'token_expires_at'         => now()->addHour(),
            'status'                   => 'connected',
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
                ->withArgs(fn (MailboxAccount $account, ?int $filterId) => $account->is($mailbox) && $filterId === null)
                ->andReturn(3);
        });

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/admin/mailboxes/{$mailbox->id}/sync")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Sync completed.']);

        $log = MailboxSyncLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($mailbox->id, $log->mailbox_account_id);
        $this->assertSame('completed', $log->status);
        $this->assertSame(3, $log->emails_fetched);
    }

    // --- Email listing ---

    public function test_authenticated_user_can_list_emails(): void
    {
        $mailbox = $this->mailbox();

        Email::create([
            'mailbox_account_id' => $mailbox->id,
            'message_id'         => 'msg-001',
            'subject'            => 'Hello World',
            'from_email'         => 'sender@test.com',
            'from_name'          => 'Sender',
            'received_at'        => now(),
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
            'email'                    => 'other@example.com',
            'display_name'             => 'Other',
            'access_token_encrypted'   => 'tok',
            'refresh_token_encrypted'  => 'ref',
            'status'                   => 'connected',
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

    // --- OAuth start ---

    public function test_admin_can_start_oauth_flow(): void
    {
        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/admin/mailboxes/oauth/start')
            ->assertOk();

        $this->assertArrayHasKey('auth_url', $response->json());
        $this->assertStringContainsString('login.microsoftonline.com', $response->json('auth_url'));
    }
}
