<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronRunLog extends Model
{
    protected $fillable = [
        'cron_job_id',
        'scheduled_at',
        'started_at',
        'ended_at',
        'status',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at'   => 'datetime',
            'ended_at'     => 'datetime',
        ];
    }

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(CronJob::class);
    }
}
