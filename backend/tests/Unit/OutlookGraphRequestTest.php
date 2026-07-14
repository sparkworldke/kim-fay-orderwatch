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

    public function test_scheduled_same_day_sync_sends_user_agent_and_filters_by_received_datetime(): void
    {
        config([
            'services.microsoft.graph_user_agent' => 'OrderWatch-Test/1.0',
            'services.microsoft.client_id' => 'client',
            'services.microsoft.client_secret' => 'secret',
            'services.microsoft.tenant_id' => 'tenant',
            'services.microsoft.redirect_uri' => 'https://example.test/callback',
            'cron.timezone' => 'Africa/Nairobi',
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
        MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'inbox-id',
            'display_name' => 'Inbox',
            'is_sync_enabled' => true,
            'trust_level' => 'standard',
        ]);

        Http::fake(function ($request) {
            $this->assertSame('OrderWatch-Test/1.0', $request->header('User-Agent')[0] ?? null);
            $this->assertSame('eventual', $request->header('ConsistencyLevel')[0] ?? null);
            $this->assertStringContainsString('/messages', $request->url());
            $this->assertStringNotContainsString('/messages/delta', $request->url());
            $this->assertStringContainsString('receivedDateTime', urldecode($request->url()));

            return Http::response(['value' => []]);
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

    public function test_same_day_window_resumes_from_last_check_and_never_before_today(): void
    {
        config(['cron.timezone' => 'Africa/Nairobi']);

        $encryption = Mockery::mock(EncryptionService::class);
        $service = new OutlookEmailService(
            $encryption,
            app(EmailFilterEngine::class),
            app(AttachmentTextExtractorService::class),
        );

        $now = \Carbon\Carbon::parse('2026-07-10 15:30:00', 'Africa/Nairobi');
        $yesterday = \Carbon\Carbon::parse('2026-07-09 18:00:00', 'Africa/Nairobi');
        $earlierToday = \Carbon\Carbon::parse('2026-07-10 12:00:00', 'Africa/Nairobi');

        $freshDay = $service->sameDaySyncWindow($yesterday, $now);
        $this->assertSame('2026-07-10', $freshDay['day']);
        $this->assertFalse($freshDay['resumed_from_watermark']);
        $this->assertTrue($freshDay['from']->equalTo($now->copy()->startOfDay()));
        $this->assertTrue($freshDay['to']->equalTo($now));

        $resumed = $service->sameDaySyncWindow($earlierToday, $now);
        $this->assertTrue($resumed['resumed_from_watermark']);
        $this->assertTrue($resumed['from']->equalTo($earlierToday->copy()->subMinutes(2)));
        $this->assertTrue($resumed['from']->greaterThanOrEqualTo($now->copy()->startOfDay()));
        $this->assertTrue($resumed['to']->equalTo($now));
    }
}