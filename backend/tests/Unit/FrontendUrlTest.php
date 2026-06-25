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
}