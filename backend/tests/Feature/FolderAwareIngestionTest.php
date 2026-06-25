<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\MailboxRuleMapping;
use App\Services\Admin\EncryptionService;
use App\Services\Email\EmailFilterEngine;
use App\Services\Email\EmailIngestionDecisionService;
use App\Services\Email\OutlookEmailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FolderAwareIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_discovery_is_recursive_and_never_auto_trusts_named_po_folders(): void
    {
        $account = $this->mailbox();
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/childFolders*' => Http::response(['value' => [[
                'id' => 'naivas-id', 'displayName' => 'Naivas POs', 'parentFolderId' => 'inbox-id',
                'childFolderCount' => 0, 'totalItemCount' => 4, 'unreadItemCount' => 1,
            ]]]),
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox*' => Http::response(['id' => 'inbox-id']),
            'https://graph.microsoft.com/v1.0/me/mailFolders?*' => Http::response(['value' => [[
                'id' => 'inbox-id', 'displayName' => 'Inbox', 'childFolderCount' => 1,
                'totalItemCount' => 10, 'unreadItemCount' => 2,
            ]]]),
        ]);

        $folders = $this->service()->discoverFolders($account, 'token');

        $this->assertCount(2, $folders);
        $this->assertDatabaseHas('mailbox_folders', [
            'external_folder_id' => 'inbox-id', 'is_sync_enabled' => true, 'trust_level' => 'standard',
        ]);
        $this->assertDatabaseHas('mailbox_folders', [
            'external_folder_id' => 'naivas-id', 'is_sync_enabled' => false,
            'is_order_folder' => false, 'trust_level' => 'untrusted',
        ]);
    }

    public function test_folder_and_rule_trust_without_po_requires_review_and_never_links_order(): void
    {
        $account = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id, 'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs', 'is_sync_enabled' => true,
            'is_order_folder' => true, 'trust_level' => 'trusted_order',
        ]);
        MailboxRuleMapping::create([
            'mailbox_account_id' => $account->id, 'mailbox_folder_id' => $folder->id,
            'existing_rule_name' => 'Naivas PO Rule', 'is_enabled' => true, 'is_trusted' => true,
        ]);
        $email = Email::create([
            'mailbox_account_id' => $account->id, 'mailbox_folder_id' => $folder->id,
            'message_id' => 'message-1', 'subject' => 'Order attached',
            'from_email' => 'unknown@example.com', 'folder' => 'Naivas POs',
        ]);

        $decision = app(EmailIngestionDecisionService::class)->evaluate($email);

        $this->assertSame('needs_review', $decision['classification']);
        $this->assertFalse($decision['po_detected']);
        $this->assertNull($email->fresh()->extracted_po_number);
        $this->assertNull($email->fresh()->matched_order_id);
    }

    public function test_deterministic_po_enters_processing_but_does_not_auto_match(): void
    {
        $account = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id, 'external_folder_id' => 'inbox-id',
            'display_name' => 'Inbox', 'is_sync_enabled' => true, 'trust_level' => 'standard',
        ]);
        $email = Email::create([
            'mailbox_account_id' => $account->id, 'mailbox_folder_id' => $folder->id,
            'message_id' => 'message-2', 'subject' => 'PO 004512 attached',
            'from_email' => 'unknown@example.com', 'folder' => 'Inbox',
        ]);

        app(EmailIngestionDecisionService::class)->evaluate($email);

        $this->assertSame('po_processing', $email->fresh()->ingestion_classification);
        $this->assertSame('004512', $email->fresh()->extracted_po_number);
        $this->assertNull($email->fresh()->matched_order_id);
    }

    public function test_folder_failures_are_isolated_and_successful_delta_is_preserved(): void
    {
        $account = $this->mailbox();
        $inbox = MailboxFolder::create([
            'mailbox_account_id' => $account->id, 'external_folder_id' => 'Inbox',
            'display_name' => 'Inbox', 'is_sync_enabled' => true, 'trust_level' => 'standard',
        ]);
        $broken = MailboxFolder::create([
            'mailbox_account_id' => $account->id, 'external_folder_id' => 'broken-id',
            'display_name' => 'Broken POs', 'is_sync_enabled' => true, 'trust_level' => 'untrusted',
        ]);
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta*' => Http::response([
                'value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/inbox-delta',
            ]),
            'https://graph.microsoft.com/v1.0/me/mailFolders/broken-id/messages/delta*' => Http::response([], 500),
        ]);
        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->once()->andReturn('token');
        $service = new OutlookEmailService($encryption, new EmailFilterEngine);

        $this->assertSame(0, $service->syncEmails($account));
        $this->assertSame('https://graph.microsoft.com/v1.0/inbox-delta', $inbox->fresh()->delta_token);
        $this->assertNotNull($broken->fresh()->last_sync_error);
    }

    public function test_folder_date_range_sync_fetches_every_matching_page(): void
    {
        $account = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'is_sync_enabled' => true,
            'trust_level' => 'standard',
        ]);

        $message = fn (string $id): array => [
            'id' => $id,
            'subject' => 'PO 123',
            'from' => ['emailAddress' => ['address' => 'buyer@example.com']],
            'receivedDateTime' => '2026-06-24T10:00:00Z',
            'hasAttachments' => false,
        ];

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages*' => Http::sequence()
                ->push([
                    'value' => [$message('msg-1')],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages?$skiptoken=page-2',
                ])
                ->push([
                    'value' => [$message('msg-2')],
                ]),
        ]);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->andReturn('token');
        $this->instance(EncryptionService::class, $encryption);

        $stats = app(OutlookEmailService::class)
            ->syncFolderDateRange($account, $folder, Carbon::parse('2026-06-24'), Carbon::parse('2026-06-24'));

        $this->assertSame(2, $stats['fetched']);
        $this->assertSame(2, $stats['stored']);
        $this->assertSame(2, Email::where('mailbox_folder_id', $folder->id)->count());
    }

    public function test_folder_date_range_sync_stops_when_graph_repeats_a_page(): void
    {
        $account = $this->mailbox();
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'is_sync_enabled' => true,
            'trust_level' => 'standard',
        ]);

        $message = [
            'id' => 'msg-repeat',
            'subject' => 'PO 123',
            'from' => ['emailAddress' => ['address' => 'buyer@example.com']],
            'receivedDateTime' => '2026-06-24T10:00:00Z',
            'hasAttachments' => false,
        ];

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages*' => Http::sequence()
                ->push([
                    'value' => [$message],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages?$skiptoken=page-2',
                ])
                ->push([
                    'value' => [$message],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/naivas-id/messages?$skiptoken=page-3',
                ]),
        ]);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->andReturn('token');
        $this->instance(EncryptionService::class, $encryption);

        $stats = app(OutlookEmailService::class)
            ->syncFolderDateRange($account, $folder, Carbon::parse('2026-06-24'), Carbon::parse('2026-06-24'));

        $this->assertSame(1, $stats['fetched']);
        $this->assertSame(1, $stats['stored']);
        $this->assertSame(1, Email::where('mailbox_folder_id', $folder->id)->count());
    }

    public function test_immutable_message_seen_in_two_folders_is_updated_not_duplicated(): void
    {
        $account = $this->mailbox();
        foreach ([['Inbox', 'Inbox'], ['orders-id', 'Orders']] as [$externalId, $name]) {
            MailboxFolder::create([
                'mailbox_account_id' => $account->id, 'external_folder_id' => $externalId,
                'display_name' => $name, 'is_sync_enabled' => true, 'trust_level' => 'standard',
            ]);
        }
        $message = [
            'id' => 'immutable-message-id', 'subject' => 'PO 004512',
            'from' => ['emailAddress' => ['address' => 'buyer@example.com']],
            'receivedDateTime' => '2026-06-22T10:00:00Z', 'hasAttachments' => false,
        ];
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta*' => Http::response(['value' => [$message]]),
            'https://graph.microsoft.com/v1.0/me/mailFolders/orders-id/messages/delta*' => Http::response(['value' => [$message]]),
        ]);
        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->once()->andReturn('token');

        (new OutlookEmailService($encryption, new EmailFilterEngine))->syncEmails($account);

        $this->assertSame(1, Email::where('mailbox_account_id', $account->id)->where('message_id', 'immutable-message-id')->count());
        $this->assertSame('Orders', Email::where('message_id', 'immutable-message-id')->value('folder'));
    }

    private function mailbox(): MailboxAccount
    {
        return MailboxAccount::create([
            'email' => uniqid('mailbox-').'@example.com', 'access_token_encrypted' => 'token',
            'refresh_token_encrypted' => 'refresh', 'token_expires_at' => now()->addHour(), 'status' => 'connected',
        ]);
    }

    private function service(): OutlookEmailService
    {
        $encryption = Mockery::mock(EncryptionService::class);
        return new OutlookEmailService($encryption, new EmailFilterEngine);
    }
}
