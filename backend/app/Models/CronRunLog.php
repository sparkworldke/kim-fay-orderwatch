<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronRunLog extends Model
{
    protected $fillable = [
        'cron_job_id',
        'triggered_by_user_id',
        'scheduled_at',
        'started_at',
        'ended_at',
        'status',
        'trigger_source', 'duration_ms', 'emails_checked', 'emails_processed',
        'sales_orders_checked', 'sales_orders_processed', 'matches_created',
        'matched_with_discrepancies_count', 'needs_review_count', 'unmatched_count',
        'skipped_count', 'error_count', 'step_status', 'error_summary', 'metadata',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at'   => 'datetime',
            'ended_at'     => 'datetime',
            'step_status'  => 'array',
            'metadata'     => 'array',
        ];
    }

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(CronJob::class);
    }
}
