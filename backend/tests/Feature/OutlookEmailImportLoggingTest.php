<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\Email;
use App\Models\EmailFilter;
use App\Models\EmailImportConfig;
use App\Models\MailboxAccount;
use App\Models\MailboxSyncItemLog;
use App\Models\MailboxSyncLog;
use App\Services\Admin\EncryptionService;
use App\Services\Email\EmailFilterEngine;
use App\Services\Email\OutlookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class OutlookEmailImportLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_are_processed_one_by_one_and_mirrored_to_database_and_file_logs(): void
    {
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'display_name' => 'Inbox',
            'access_token_encrypted' => 'encrypted-token',
            'refresh_token_encrypted' => 'encrypted-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        $run = MailboxSyncLog::create([
            'mailbox_account_id' => $account->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        // Normal inbox sync must store mail even when active sender/organization
        // rules do not match. Those controls must not become hidden import gates.
        EmailImportConfig::create([
            'sender_pattern' => 'orders@allowed.example',
            'display_name' => 'Allowed orders',
            'match_mode' => 'exact',
            'approval_status' => 'approved',
            'is_active' => true,
            'customer_id' => AcumaticaCustomer::create([
                'acumatica_id' => 'CHANDARA-OPS',
                'name' => 'Chandara Ops',
                'is_main_account' => true,
            ])->id,
        ]);
        EmailFilter::create([
            'name' => 'Only invoices',
            'conditions' => [['type' => 'subject_keyword', 'value' => 'invoice']],
            'is_active' => true,
        ]);

        Email::create($this->storedEmail($account, 'update-me', ['is_read' => false]));
        Email::create($this->storedEmail($account, 'unchanged'));
        Email::create($this->storedEmail($account, 'delete-me'));

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox*' => Http::response([
                'id' => 'Inbox', 'displayName' => 'Inbox', 'childFolderCount' => 0,
                'totalItemCount' => 6, 'unreadItemCount' => 2,
            ]),
            'https://graph.microsoft.com/v1.0/me/mailFolders?*' => Http::response([
                'value' => [[
                    'id' => 'Inbox', 'displayName' => 'Inbox', 'childFolderCount' => 0,
                    'totalItemCount' => 6, 'unreadItemCount' => 2,
                ]],
            ]),
            'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta*' => Http::response([
                'value' => [
                    $this->graphMessage('create-first', ['subject' => 'First']),
                    $this->graphMessage('update-me', ['isRead' => true]),
                    $this->graphMessage('unchanged'),
                    ['id' => 'delete-me', '@removed' => ['reason' => 'deleted']],
                    ['subject' => 'Missing id must not stop the page', 'bodyPreview' => 'private body'],
                    $this->graphMessage('create-after-failure', ['subject' => 'Still imported']),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta/final-token',
            ]),
        ]);

        $fileLogger = Mockery::mock(LoggerInterface::class);
        $fileLogger->shouldReceive('info')->times(5)->withArgs(function (string $event, array $context) use ($run): bool {
            $this->assertContains($event, [
                'email_created',
                'email_updated',
                'email_skipped',
                'email_deleted',
            ]);
            $this->assertSame($run->id, $context['sync_run_id']);
            $this->assertArrayNotHasKey('subject', $context);
            $this->assertArrayNotHasKey('body_preview', $context);
            $this->assertArrayNotHasKey('to_recipients', $context);
            $this->assertArrayNotHasKey('access_token', $context);

            return true;
        });
        $fileLogger->shouldReceive('error')->once()->withArgs(function (string $event, array $context) use ($run): bool {
            $this->assertSame('email_failed', $event);
            $this->assertSame($run->id, $context['sync_run_id']);
            $this->assertSame(5, $context['attempts']);
            $this->assertArrayNotHasKey('error_message', $context);

            return true;
        });
        Log::shouldReceive('channel')->with('mailbox_sync')->times(6)->andReturn($fileLogger);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->once()->with('encrypted-token')->andReturn('access-token');

        $this->app->instance(EncryptionService::class, $encryption);
        $service = $this->app->make(OutlookEmailService::class);

        $this->assertSame(6, $service->syncEmails($account, null, $run));

        $this->assertDatabaseHas('emails', ['message_id' => 'create-first', 'subject' => 'First']);
        $this->assertDatabaseHas('emails', ['message_id' => 'update-me', 'is_read' => 1]);
        $this->assertDatabaseMissing('emails', ['message_id' => 'delete-me']);
        $this->assertDatabaseHas('emails', ['message_id' => 'create-after-failure']);
        $this->assertDatabaseHas('emails', [
            'message_id' => 'create-first',
            'import_guardrail_status' => 'unrecognized',
        ]);
        $this->assertDatabaseCount('emails', 4);

        $run->refresh();
        $this->assertSame(6, $run->emails_fetched);
        $this->assertSame(2, $run->emails_created);
        $this->assertSame(2, $run->emails_updated); // legacy row is enriched with its discovered folder
        $this->assertSame(0, $run->emails_skipped);
        $this->assertSame(1, $run->emails_deleted);
        $this->assertSame(1, $run->emails_failed);

        $this->assertDatabaseCount('mailbox_sync_item_logs', 6);
        $this->assertSame(
            ['created', 'updated', 'updated', 'deleted', 'failed', 'created'],
            MailboxSyncItemLog::orderBy('id')->pluck('outcome')->all(),
        );
        $this->assertDatabaseHas('mailbox_sync_item_logs', [
            'mailbox_sync_log_id' => $run->id,
            'message_id' => null,
            'outcome' => 'failed',
            'reason' => 'processing_failed',
            'attempts' => 5,
        ]);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/delta/final-token',
            $account->folders()->where('display_name', 'Inbox')->value('delta_token'),
        );
    }

    public function test_imported_email_is_tagged_with_approved_sender_config_and_customer(): void
    {
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'display_name' => 'Inbox',
            'access_token_encrypted' => 'encrypted-token',
            'refresh_token_encrypted' => 'encrypted-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        $customer = AcumaticaCustomer::create([
            'acumatica_id' => 'CHANDARA-BRANCH',
            'name' => 'Chandara Branch',
            'is_main_account' => true,
        ]);

        EmailImportConfig::create([
            'sender_pattern' => '/^([a-z0-9-]+)@chandara-supermarket\.com$/i',
            'match_mode' => 'regex',
            'is_wildcard' => true,
            'display_name' => 'Chandara wildcard',
            'customer_id' => $customer->id,
            'branch_tag_pattern' => '/^([a-z0-9-]+)@chandara-supermarket\.com$/i',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox' => Http::response([
                'id' => 'Inbox', 'displayName' => 'Inbox', 'childFolderCount' => 0,
                'totalItemCount' => 1, 'unreadItemCount' => 1,
            ]),
            'https://graph.microsoft.com/v1.0/me/mailFolders?*' => Http::response([
                'value' => [[
                    'id' => 'Inbox', 'displayName' => 'Inbox', 'childFolderCount' => 0,
                    'totalItemCount' => 1, 'unreadItemCount' => 1,
                ]],
            ]),
            'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta*' => Http::response([
                'value' => [
                    $this->graphMessage('config-match-1', [
                        'from' => ['emailAddress' => ['address' => 'karen@chandara-supermarket.com', 'name' => 'Karen']],
                    ]),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta/final-token',
            ]),
        ]);

        $fileLogger = Mockery::mock(LoggerInterface::class);
        $fileLogger->shouldReceive('info')->andReturnNull();
        $fileLogger->shouldReceive('error')->andReturnNull();
        Log::shouldReceive('channel')->andReturn($fileLogger);

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->once()->with('encrypted-token')->andReturn('access-token');

        $this->app->instance(EncryptionService::class, $encryption);
        $service = $this->app->make(OutlookEmailService::class);
        $run = MailboxSyncLog::create([
            'mailbox_account_id' => $account->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        $service->syncEmails($account, null, $run);

        $this->assertDatabaseHas('emails', [
            'message_id' => 'config-match-1',
            'matched_customer_id' => $customer->id,
            'matched_branch_tag' => 'karen',
            'import_guardrail_status' => 'matched',
            'import_match_strategy' => 'regex',
        ]);
    }

    private function graphMessage(string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'subject' => 'Subject',
            'from' => ['emailAddress' => ['address' => 'sender@example.com', 'name' => 'Sender']],
            'toRecipients' => [],
            'bodyPreview' => 'Preview',
            'isRead' => false,
            'receivedDateTime' => '2026-06-20T10:00:00Z',
            'hasAttachments' => false,
        ], $overrides);
    }

    private function storedEmail(MailboxAccount $account, string $id, array $overrides = []): array
    {
        return array_merge([
            'mailbox_account_id' => $account->id,
            'message_id' => $id,
            'subject' => 'Subject',
            'from_email' => 'sender@example.com',
            'from_name' => 'Sender',
            'to_recipients' => [],
            'body_preview' => 'Preview',
            'is_read' => false,
            'received_at' => '2026-06-20 10:00:00',
            'folder' => 'Inbox',
            'has_attachments' => false,
        ], $overrides);
    }
}
