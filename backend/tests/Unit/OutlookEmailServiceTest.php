<?php

namespace Tests\Unit;

use App\Services\Admin\EncryptionService;
use App\Services\Email\EmailFilterEngine;
use App\Services\Email\OutlookEmailService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class OutlookEmailServiceTest extends TestCase
{
    private OutlookEmailService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.client_id'     => 'test-client-id',
            'services.microsoft.client_secret'  => 'test-client-secret',
            'services.microsoft.tenant_id'     => 'test-tenant',
            'services.microsoft.redirect_uri'   => 'https://example.com/callback',
        ]);

        $encryption    = Mockery::mock(EncryptionService::class);
        $filterEngine  = Mockery::mock(EmailFilterEngine::class);
        $this->service = new OutlookEmailService($encryption, $filterEngine);
    }

    public function test_get_auth_url_contains_required_oauth_parameters(): void
    {
        $url = $this->service->getAuthUrl('my-csrf-state');

        $this->assertStringContainsString('login.microsoftonline.com', $url);
        $this->assertStringContainsString('test-tenant', $url);
        $this->assertStringContainsString('oauth2/v2.0/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=my-csrf-state', $url);
    }

    public function test_get_auth_url_requests_mail_read_scope(): void
    {
        $url = $this->service->getAuthUrl('state-abc');

        $this->assertStringContainsString('Mail.Read', urldecode($url));
        $this->assertStringContainsString('offline_access', urldecode($url));
    }

    public function test_get_auth_url_includes_redirect_uri(): void
    {
        $url = $this->service->getAuthUrl('s');

        $this->assertStringContainsString(urlencode('https://example.com/callback'), $url);
    }

    public function test_handle_callback_throws_on_token_exchange_failure(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OAuth code exchange failed/');

        $this->service->handleCallback('bad-code');
    }

    public function test_different_states_produce_different_auth_urls(): void
    {
        $url1 = $this->service->getAuthUrl('state-one');
        $url2 = $this->service->getAuthUrl('state-two');

        $this->assertNotSame($url1, $url2);
        $this->assertStringContainsString('state=state-one', $url1);
        $this->assertStringContainsString('state=state-two', $url2);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
