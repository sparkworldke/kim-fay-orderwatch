<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_redirects_to_frontend_mailbox_route_on_error(): void
    {
        config([
            'app.url' => 'https://api.orderwatch.test',
            'app.frontend_url' => 'https://staging.orderwatch.test',
        ]);

        $this->get('/api/admin/mailboxes/oauth/callback?error=access_denied&error_description=Mailbox+connection+failed')
            ->assertRedirect('https://staging.orderwatch.test/app/mailbox?error=Mailbox+connection+failed');
    }

    public function test_oauth_callback_redirects_to_frontend_mailbox_route_on_invalid_state(): void
    {
        config([
            'app.url' => 'https://api.orderwatch.test',
            'app.frontend_url' => 'https://orderwatch.production.test',
        ]);

        $this->get('/api/admin/mailboxes/oauth/callback?state=invalid&code=abc123')
            ->assertRedirect('https://orderwatch.production.test/app/mailbox?error=invalid_state');
    }
}
