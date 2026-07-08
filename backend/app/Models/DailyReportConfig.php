<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DailyReportConfig extends Model
{
    protected $fillable = [
        'name',
        'is_enabled',
        'send_time',
        'timezone',
        'recipients_json',
        'reply_to_json',
        'subject_template',
        'include_ai_insights',
        'include_comparison',
        'include_mtd',
        'include_customer_highlights',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'recipients_json' => 'array',
            'reply_to_json' => 'array',
            'include_ai_insights' => 'boolean',
            'include_comparison' => 'boolean',
            'include_mtd' => 'boolean',
            'include_customer_highlights' => 'boolean',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DailyReportRun::class, 'report_config_id');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(DailyReportRun::class, 'report_config_id')->latestOfMany();
    }

    public static function singleton(): self
    {
        return static::query()->firstOrCreate([], [
            'name' => 'Daily Executive Exceptions Report',
            'is_enabled' => true,
            'send_time' => '08:00',
            'timezone' => 'Africa/Nairobi',
            'recipients_json' => [],
            'reply_to_json' => [],
            'subject_template' => 'OrderWatch Executive Exceptions – {report_date}',
            'include_ai_insights' => false,
            'include_comparison' => false,
            'include_mtd' => false,
            'include_customer_highlights' => false,
        ]);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->normalizeEmails($this->recipients_json ?? []);
    }

    /** @return list<string> */
    public function replyTo(): array
    {
        return $this->normalizeEmails($this->reply_to_json ?? []);
    }

    /** @param  list<mixed>  $emails
     * @return list<string> */
    private function normalizeEmails(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => is_string($email) ? strtolower(trim($email)) : null)
            ->filter(fn (?string $email) => $email && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}