<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CronJob extends Model
{
    protected $fillable = [
        'job_key',
        'name',
        'description',
        'is_enabled',
        'cron_expression',
        'frequency_label',
        'trigger_type',
        'command',
        'status',
        'last_run_at',
        'last_success_at',
        'last_failure_at',
        'last_run_status',
        'last_duration_ms',
        'next_run_at',
        'settings',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'next_run_at' => 'datetime',
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function runLogs(): HasMany
    {
        return $this->hasMany(CronRunLog::class);
    }

    public static function hourlyAutoMatch(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'email-sales-order-auto-match'],
            [
                'name' => 'Email ↔ Sales Order Auto Match',
                'description' => 'Hourly Outlook, Acumatica Sales Order, and guarded matching pipeline.',
                'is_enabled' => true, 'frequency_label' => 'Hourly', 'cron_expression' => '0 * * * *',
                'trigger_type' => 'scheduler', 'command' => 'php artisan orderwatch:hourly-auto-match',
                'status' => 'active', 'next_run_at' => now()->addHour()->startOfHour(),
                'settings' => [
                    'email_sync_enabled' => true, 'acumatica_sync_enabled' => true,
                    'matching_enabled' => true, 'sales_order_lookback_days' => 7,
                    'deterministic_auto_link' => true, 'ai_auto_link' => false,
                ],
            ],
        );
    }
}
