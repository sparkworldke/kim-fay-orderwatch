<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const MAIL_MAILER = 'mail.mailer';

    public const MAIL_SMTP_HOST = 'mail.smtp.host';

    public const MAIL_SMTP_PORT = 'mail.smtp.port';

    public const MAIL_SMTP_SCHEME = 'mail.smtp.scheme';

    public const MAIL_SMTP_USERNAME = 'mail.smtp.username';

    public const MAIL_SMTP_PASSWORD = 'mail.smtp.password';

    public const MAIL_FROM_ADDRESS = 'mail.from.address';

    public const MAIL_FROM_NAME = 'mail.from.name';

    public const RESEND_API_KEY = 'services.resend.key';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function valueFor(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, ?string $value): self
    {
        return static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
