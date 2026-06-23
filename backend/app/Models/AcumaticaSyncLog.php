<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaSyncLog extends Model
{
    protected $fillable = [
        'sync_type',
        'cron_run_log_id',
        'started_at',
        'ended_at',
        'record_count',
        'success_count',
        'failed_count',
        'status',
        'error_message',
        'filters',
        'trigger_type',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
            'filters'    => 'array',
        ];
    }
}
