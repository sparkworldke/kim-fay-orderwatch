<?php

namespace App\Services\Admin;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

class MailSettingsService
{
    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    public function applyRuntimeConfig(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $mailer = SystemSetting::valueFor(SystemSetting::MAIL_MAILER);
        if (in_array($mailer, ['smtp', 'resend'], true)) {
            config(['mail.default' => $mailer]);
        }

        $host = SystemSetting::valueFor(SystemSetting::MAIL_SMTP_HOST);
        if ($host) {
            config(['mail.mailers.smtp.host' => $host]);
        }

        $port = SystemSetting::valueFor(SystemSetting::MAIL_SMTP_PORT);
        if ($port) {
            config(['mail.mailers.smtp.port' => (int) $port]);
        }

        $scheme = $this->normalizeSmtpScheme(SystemSetting::valueFor(SystemSetting::MAIL_SMTP_SCHEME));
        if ($scheme) {
            config(['mail.mailers.smtp.scheme' => $scheme]);
        }

        $username = SystemSetting::valueFor(SystemSetting::MAIL_SMTP_USERNAME);
        if ($username) {
            config(['mail.mailers.smtp.username' => $username]);
        }

        $encryptedPassword = SystemSetting::valueFor(SystemSetting::MAIL_SMTP_PASSWORD);
        if ($encryptedPassword) {
            $password = $this->encryption->decrypt($encryptedPassword);
            if ($password !== null && $password !== '') {
                config(['mail.mailers.smtp.password' => $password]);
            }
        }

        $fromAddress = SystemSetting::valueFor(SystemSetting::MAIL_FROM_ADDRESS);
        if ($fromAddress) {
            config(['mail.from.address' => $fromAddress]);
        }

        $fromName = SystemSetting::valueFor(SystemSetting::MAIL_FROM_NAME);
        if ($fromName) {
            config(['mail.from.name' => $fromName]);
        }

        $resendKey = SystemSetting::valueFor(SystemSetting::RESEND_API_KEY);
        if ($resendKey) {
            $key = $this->encryption->decrypt($resendKey);
            if ($key !== null && $key !== '') {
                config(['services.resend.key' => $key]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $mailer = SystemSetting::valueFor(SystemSetting::MAIL_MAILER) ?? config('mail.default', 'smtp');
        $encryptedPassword = SystemSetting::valueFor(SystemSetting::MAIL_SMTP_PASSWORD);
        $encryptedResend = SystemSetting::valueFor(SystemSetting::RESEND_API_KEY);

        return [
            'mailer' => in_array($mailer, ['smtp', 'resend'], true) ? $mailer : 'smtp',
            'smtp_host' => SystemSetting::valueFor(SystemSetting::MAIL_SMTP_HOST) ?? config('mail.mailers.smtp.host'),
            'smtp_port' => (int) (SystemSetting::valueFor(SystemSetting::MAIL_SMTP_PORT) ?? config('mail.mailers.smtp.port', 587)),
            'smtp_scheme' => $this->displaySmtpScheme(
                SystemSetting::valueFor(SystemSetting::MAIL_SMTP_SCHEME) ?? config('mail.mailers.smtp.scheme', 'tls'),
            ),
            'smtp_username' => SystemSetting::valueFor(SystemSetting::MAIL_SMTP_USERNAME) ?? config('mail.mailers.smtp.username'),
            'smtp_password_configured' => is_string($encryptedPassword) && $encryptedPassword !== '',
            'smtp_password_preview' => $encryptedPassword
                ? $this->encryption->mask($this->encryption->decrypt($encryptedPassword), 2)
                : null,
            'from_address' => SystemSetting::valueFor(SystemSetting::MAIL_FROM_ADDRESS) ?? config('mail.from.address'),
            'from_name' => SystemSetting::valueFor(SystemSetting::MAIL_FROM_NAME) ?? config('mail.from.name'),
            'resend_configured' => (is_string($encryptedResend) && $encryptedResend !== '')
                || (bool) config('services.resend.key'),
            'smtp_configured' => $this->smtpConfigured(),
            'updated_at' => SystemSetting::query()
                ->whereIn('key', [
                    SystemSetting::MAIL_MAILER,
                    SystemSetting::MAIL_SMTP_HOST,
                    SystemSetting::MAIL_SMTP_PORT,
                    SystemSetting::MAIL_SMTP_USERNAME,
                    SystemSetting::MAIL_SMTP_PASSWORD,
                    SystemSetting::MAIL_FROM_ADDRESS,
                ])
                ->max('updated_at'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function update(array $data): array
    {
        if (isset($data['mailer'])) {
            SystemSetting::setValue(SystemSetting::MAIL_MAILER, $data['mailer']);
        }

        if (array_key_exists('smtp_host', $data)) {
            SystemSetting::setValue(SystemSetting::MAIL_SMTP_HOST, $data['smtp_host'] ?: null);
        }

        if (array_key_exists('smtp_port', $data)) {
            SystemSetting::setValue(SystemSetting::MAIL_SMTP_PORT, $data['smtp_port'] ? (string) $data['smtp_port'] : null);
        }

        if (array_key_exists('smtp_scheme', $data)) {
            SystemSetting::setValue(
                SystemSetting::MAIL_SMTP_SCHEME,
                $data['smtp_scheme'] ? $this->normalizeSmtpScheme((string) $data['smtp_scheme']) : null,
            );
        }

        if (array_key_exists('smtp_username', $data)) {
            SystemSetting::setValue(SystemSetting::MAIL_SMTP_USERNAME, $data['smtp_username'] ?: null);
        }

        if (! empty($data['smtp_password'])) {
            SystemSetting::setValue(
                SystemSetting::MAIL_SMTP_PASSWORD,
                $this->encryption->encrypt((string) $data['smtp_password']),
            );
        }

        if (array_key_exists('from_address', $data)) {
            SystemSetting::setValue(SystemSetting::MAIL_FROM_ADDRESS, $data['from_address'] ?: null);
        }

        if (array_key_exists('from_name', $data)) {
            SystemSetting::setValue(SystemSetting::MAIL_FROM_NAME, $data['from_name'] ?: null);
        }

        if (! empty($data['resend_api_key'])) {
            SystemSetting::setValue(
                SystemSetting::RESEND_API_KEY,
                $this->encryption->encrypt((string) $data['resend_api_key']),
            );
        }

        $this->applyRuntimeConfig();

        if (method_exists(app('mail.manager'), 'forgetDrivers')) {
            app('mail.manager')->forgetDrivers();
        }

        return $this->present();
    }

    public function smtpConfigured(): bool
    {
        $host = SystemSetting::valueFor(SystemSetting::MAIL_SMTP_HOST) ?? config('mail.mailers.smtp.host');
        $from = SystemSetting::valueFor(SystemSetting::MAIL_FROM_ADDRESS) ?? config('mail.from.address');

        return (bool) $host
            && (bool) $from
            && $from !== 'hello@example.com';
    }

    private function normalizeSmtpScheme(?string $scheme): ?string
    {
        if ($scheme === null || trim($scheme) === '') {
            return null;
        }

        return match (strtolower(trim($scheme))) {
            'ssl', 'smtps' => 'smtps',
            'tls', 'smtp' => 'smtp',
            default => $scheme,
        };
    }

    private function displaySmtpScheme(?string $scheme): string
    {
        return $this->normalizeSmtpScheme($scheme) === 'smtps' ? 'ssl' : 'tls';
    }
}