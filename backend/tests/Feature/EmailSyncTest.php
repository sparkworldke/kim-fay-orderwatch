<?php

namespace Tests\Feature;

use App\Jobs\SyncMailboxJob;
use App\Models\Email;
use App\Models\EmailFilter;
use App\Models\MailboxAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_sync_endpoint_dispatches_job(): void
    {
        Queue::fake();
        $mailbox = $this->mailbox();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/admin/mailboxes/{$mailbox->id}/sync")
            ->assertOk();

        Queue::assertPushed(SyncMailboxJob::class, fn ($job) => $job->mailboxAccountId === $mailbox->id);
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
                'name'  => 'Gmail Domain',
                'type'  => 'sender_domain',
                'value' => 'gmail.com',
            ])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Gmail Domain', 'type' => 'sender_domain', 'match_count' => 0]);

        $this->assertDatabaseHas('email_filters', ['name' => 'Gmail Domain']);
    }

    public function test_email_filter_match_count_reflects_stored_emails(): void
    {
        $mailbox = $this->mailbox();
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'x1', 'subject' => 'Hi', 'from_email' => 'alice@gmail.com', 'received_at' => now()]);
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'x2', 'subject' => 'Hi', 'from_email' => 'bob@yahoo.com',  'received_at' => now()]);

        $response = $this->actingAs($this->regularUser(), 'sanctum')
            ->postJson('/api/email-filters', [
                'name'  => 'Gmail',
                'type'  => 'sender_domain',
                'value' => 'gmail.com',
            ])
            ->assertCreated();

        $this->assertEquals(1, $response->json('match_count'));
    }

    public function test_user_can_update_a_filter(): void
    {
        $filter = EmailFilter::create(['name' => 'Old', 'type' => 'sender_email', 'value' => 'old@old.com', 'is_active' => true]);

        $this->actingAs($this->regularUser(), 'sanctum')
            ->patchJson("/api/email-filters/{$filter->id}", ['name' => 'New Name', 'is_active' => false])
            ->assertOk()
            ->assertJsonFragment(['name' => 'New Name', 'is_active' => false]);
    }

    public function test_user_can_delete_a_filter(): void
    {
        $filter = EmailFilter::create(['name' => 'To Delete', 'type' => 'sender_email', 'value' => 'd@d.com', 'is_active' => true]);

        $this->actingAs($this->regularUser(), 'sanctum')
            ->deleteJson("/api/email-filters/{$filter->id}")
            ->assertOk();

        $this->assertDatabaseMissing('email_filters', ['id' => $filter->id]);
    }

    public function test_filter_validation_rejects_invalid_type(): void
    {
        $this->actingAs($this->regularUser(), 'sanctum')
            ->postJson('/api/email-filters', [
                'name'  => 'Bad',
                'type'  => 'invalid_type',
                'value' => 'whatever',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_filter_list_returns_match_counts_for_all_filters(): void
    {
        $mailbox = $this->mailbox();
        Email::create(['mailbox_account_id' => $mailbox->id, 'message_id' => 'y1', 'subject' => 'Order shipped', 'from_email' => 'shop@store.com', 'received_at' => now()]);

        EmailFilter::create(['name' => 'Store', 'type' => 'sender_domain', 'value' => 'store.com', 'is_active' => true]);
        EmailFilter::create(['name' => 'Orders', 'type' => 'subject_keyword', 'value' => 'order', 'is_active' => true]);

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
