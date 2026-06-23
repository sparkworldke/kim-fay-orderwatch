<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyReportRun extends Model
{
    protected $fillable = [
        'report_config_id',
        'report_date',
        'started_at',
        'completed_at',
        'sent_at',
        'status',
        'ai_status',
        'delivery_status',
        'recipient_count',
        'duration_ms',
        'error_summary',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload_json' => 'array',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(DailyReportConfig::class, 'report_config_id');
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(DailyReportDeliveryLog::class);
    }
}