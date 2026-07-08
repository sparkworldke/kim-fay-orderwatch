<?php

namespace Tests\Unit;

use App\Support\FrontendUrl;
use Tests\TestCase;

class FrontendUrlTest extends TestCase
{
    public function test_base_returns_configured_frontend_url_without_trailing_slash(): void
    {
        config(['app.frontend_url' => 'https://orderwatch.test/']);

        $this->assertSame('https://orderwatch.test', FrontendUrl::base());
    }

    public function test_path_builds_absolute_frontend_urls(): void
    {
        config(['app.frontend_url' => 'https://orderwatch.test']);

        $this->assertSame('https://orderwatch.test/app', FrontendUrl::path('/app'));
        $this->assertSame('https://orderwatch.test/login', FrontendUrl::path('login'));
    }

    public function test_path_appends_query_parameters_for_frontend_routes(): void
    {
        config(['app.frontend_url' => 'https://staging.orderwatch.test']);

        $this->assertSame(
            'https://staging.orderwatch.test/app/mailbox?connected=1&email=ops%40kimfay.test',
            FrontendUrl::path('/app/mailbox', [
                'connected' => 1,
                'email' => 'ops@kimfay.test',
            ]),
        );
    }

    public function test_base_falls_back_to_services_frontend_url_when_app_value_is_blank(): void
    {
        config([
            'app.frontend_url' => '',
            'services.microsoft.frontend_url' => 'https://orderwatch.production.test/',
        ]);

        $this->assertSame('https://orderwatch.production.test', FrontendUrl::base());
    }
}
