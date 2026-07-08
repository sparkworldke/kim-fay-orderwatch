<?php

namespace Tests\Unit;

use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Services\Admin\EncryptionService;
use App\Services\Email\AttachmentTextExtractorService;
use App\Services\Email\EmailFilterEngine;
use App\Services\Email\OutlookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class OutlookGraphRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_delta_sync_sends_user_agent_and_consistency_level_when_filtering(): void
    {
        config([
            'services.microsoft.graph_user_agent' => 'OrderWatch-Test/1.0',
            'services.microsoft.client_id' => 'client',
            'services.microsoft.client_secret' => 'secret',
            'services.microsoft.tenant_id' => 'tenant',
            'services.microsoft.redirect_uri' => 'https://example.test/callback',
        ]);

        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'display_name' => 'Inbox',
            'access_token_encrypted' => 'token',
            'refresh_token_encrypted' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'sync_from_date' => now()->subDay(),
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'inbox-id',
            'display_name' => 'Inbox',
            'is_sync_enabled' => true,
            'trust_level' => 'standard',
        ]);

        Http::fake(function ($request) {
            $this->assertSame('OrderWatch-Test/1.0', $request->header('User-Agent')[0] ?? null);
            $this->assertSame('eventual', $request->header('ConsistencyLevel')[0] ?? null);
            $this->assertStringContainsString('/messages/delta', $request->url());

            return Http::response([
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?token=delta',
                'value' => [],
            ]);
        });

        $encryption = Mockery::mock(EncryptionService::class);
        $encryption->shouldReceive('decrypt')->andReturn('token');
        $this->instance(EncryptionService::class, $encryption);

        $service = new OutlookEmailService(
            $encryption,
            app(EmailFilterEngine::class),
            app(AttachmentTextExtractorService::class),
        );

        $count = $service->syncEmails($account);

        $this->assertSame(0, $count);
        Http::assertSentCount(1);
    }
}