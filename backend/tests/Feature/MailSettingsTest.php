<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_smtp_settings_to_database(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);

        $response = $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
            'mailer' => 'smtp',
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_scheme' => 'tls',
            'smtp_username' => 'noreply@kimfay.com',
            'smtp_password' => 'secret-pass',
            'from_address' => 'noreply@kimfay.com',
            'from_name' => 'Kim-Fay OrderWatch',
        ]);

        $response->assertOk();
        $response->assertJsonPath('mailer', 'smtp');
        $response->assertJsonPath('smtp_host', 'smtp.office365.com');
        $response->assertJsonPath('smtp_password_configured', true);
        $response->assertJsonPath('from_address', 'noreply@kimfay.com');

        $this->assertSame('smtp.office365.com', config('mail.mailers.smtp.host'));
        $this->assertSame('secret-pass', config('mail.mailers.smtp.password'));
        $this->assertSame('noreply@kimfay.com', config('mail.from.address'));
        $this->assertNotNull(SystemSetting::valueFor(SystemSetting::MAIL_SMTP_PASSWORD));
    }
}