<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\OrderMatchSyncRunEmail;
use App\Models\User;
use App\Services\Admin\EncryptionService;
use App\Services\Email\EmailFilterEngine;
use App\Services\Email\OutlookEmailService;
use App\Services\OrderMatch\OrderMatchFolderSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FolderSyncPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_sync_persists_emails_and_links_them_to_sync_run(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'Administrator', 'is_super_admin' => true]);
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
            'token_expires_at' => now()->addHour(),
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'is_sync_enabled' => true,
            'trust_level' => 'trusted_order',
        ]);

        $message = [
            'id' => 'msg-persist-1',
            'subject' => 'PO confirmation',
            'from' => ['emailAddress' => ['address' => 'notification@naivas.net', 'name' => 'Naivas']],
            'receivedDateTime' => '2026-06-24T10:00:00Z',
            'hasAttachments' => false,
            'bodyPreview' => 'PO 12345',
        ];

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages*' => Http::response(['value' => [$message]]),
        ]);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->andReturn('token');
        $this->instance(EncryptionService::class, $encryption);

        $run = app(OrderMatchFolderSyncService::class)->sync($folder, '2026-06-24', '2026-06-24', $user->id);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->emails_queued);
        $this->assertDatabaseHas('emails', [
            'mailbox_account_id' => $account->id,
            'message_id' => 'msg-persist-1',
            'mailbox_folder_id' => $folder->id,
        ]);

        $email = Email::where('message_id', 'msg-persist-1')->first();
        $this->assertNotNull($email);
        $this->assertDatabaseHas('order_match_sync_run_emails', [
            'order_match_sync_run_id' => $run->id,
            'email_id' => $email->id,
            'outcome' => 'created',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/mailbox-folder-sync-runs/{$run->id}/emails")
            ->assertOk()
            ->assertJsonPath('emails.0.id', $email->id)
            ->assertJsonPath('sync_run.emails_stored', 1);
    }

    public function test_folder_date_range_sync_reimports_read_emails_already_in_database(): void
    {
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
            'token_expires_at' => now()->addHour(),
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'is_sync_enabled' => true,
            'trust_level' => 'trusted_order',
        ]);

        Email::create([
            'mailbox_account_id' => $account->id,
            'mailbox_folder_id' => $folder->id,
            'external_folder_id' => $folder->external_folder_id,
            'message_id' => 'msg-read-existing',
            'subject' => 'PO already imported',
            'from_email' => 'notification@naivas.net',
            'received_at' => '2026-06-24T10:00:00Z',
            'folder' => 'Naivas POs',
            'is_read' => true,
            'has_attachments' => false,
        ]);

        $message = [
            'id' => 'msg-read-existing',
            'subject' => 'PO already imported',
            'from' => ['emailAddress' => ['address' => 'notification@naivas.net', 'name' => 'Naivas']],
            'receivedDateTime' => '2026-06-24T10:00:00Z',
            'isRead' => true,
            'hasAttachments' => false,
            'bodyPreview' => 'PO 12345',
        ];

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages*' => Http::response(['value' => [$message]]),
        ]);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->andReturn('token');
        $this->instance(EncryptionService::class, $encryption);

        $stats = app(OutlookEmailService::class)
            ->syncFolderDateRange($account, $folder, Carbon::parse('2026-06-24'), Carbon::parse('2026-06-24'));

        $this->assertSame(1, $stats['fetched']);
        $this->assertSame(1, $stats['stored']);
        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, Email::where('message_id', 'msg-read-existing')->count());
    }

    public function test_folder_sync_tolerates_duplicate_email_records_for_same_run(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'Administrator', 'is_super_admin' => true]);
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
            'token_expires_at' => now()->addHour(),
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'carrefour-id',
            'display_name' => 'Carrefour POs',
            'is_sync_enabled' => true,
            'trust_level' => 'trusted_order',
        ]);

        $email = Email::create([
            'mailbox_account_id' => $account->id,
            'mailbox_folder_id' => $folder->id,
            'message_id' => 'msg-carrefour-dup',
            'subject' => 'C4 GCM XGCM 26021220',
            'from_email' => 'kencarrefourorders@maf.ae',
            'received_at' => now(),
            'folder' => 'Carrefour POs',
        ]);

        $outlook = Mockery::mock(OutlookEmailService::class);
        $outlook->shouldReceive('syncFolderDateRange')->once()->andReturn([
            'fetched' => 2,
            'created' => 0,
            'updated' => 1,
            'stored' => 1,
            'skipped' => 0,
            'failed' => 0,
            'email_records' => [
                ['email_id' => $email->id, 'outcome' => 'updated'],
                ['email_id' => $email->id, 'outcome' => 'updated'],
            ],
        ]);
        $this->instance(OutlookEmailService::class, $outlook);

        $run = app(OrderMatchFolderSyncService::class)->start($folder, '2026-06-24', '2026-06-24', $user->id);
        app(OrderMatchFolderSyncService::class)->execute($run, '2026-06-24', '2026-06-24');

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, OrderMatchSyncRunEmail::where('order_match_sync_run_id', $run->id)->count());
        $this->assertDatabaseHas('order_match_sync_run_emails', [
            'order_match_sync_run_id' => $run->id,
            'email_id' => $email->id,
            'outcome' => 'updated',
        ]);
    }

    public function test_folder_date_range_sync_skips_duplicate_internet_message_ids(): void
    {
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
            'token_expires_at' => now()->addHour(),
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'carrefour-id',
            'display_name' => 'Carrefour POs',
            'is_sync_enabled' => true,
            'trust_level' => 'trusted_order',
        ]);

        $messages = [
            [
                'id' => 'graph-id-1',
                'internetMessageId' => '<duplicate-carrefour@mail.gmail.com>',
                'subject' => 'C4 GCM XGCM 26021220',
                'from' => ['emailAddress' => ['address' => 'kencarrefourorders@maf.ae', 'name' => 'Carrefour']],
                'receivedDateTime' => '2026-06-24T10:00:00Z',
                'hasAttachments' => false,
                'bodyPreview' => 'PO',
            ],
            [
                'id' => 'graph-id-2',
                'internetMessageId' => '<duplicate-carrefour@mail.gmail.com>',
                'subject' => 'C4 GCM XGCM 26021220',
                'from' => ['emailAddress' => ['address' => 'kencarrefourorders@maf.ae', 'name' => 'Carrefour']],
                'receivedDateTime' => '2026-06-24T10:00:00Z',
                'hasAttachments' => false,
                'bodyPreview' => 'PO',
            ],
        ];

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/carrefour-id/messages*' => Http::response(['value' => $messages]),
        ]);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->andReturn('token');
        $this->instance(EncryptionService::class, $encryption);

        $stats = app(OutlookEmailService::class)
            ->syncFolderDateRange($account, $folder, Carbon::parse('2026-06-24'), Carbon::parse('2026-06-24'));

        $this->assertSame(1, $stats['fetched']);
        $this->assertSame(1, $stats['stored']);
        $this->assertCount(1, $stats['email_records']);
        $this->assertSame(1, Email::count());
    }
}