<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CronJob extends Model
{
    protected $fillable = [
        'name',
        'description',
        'cron_expression',
        'command',
        'status',
        'last_run_at',
        'last_run_status',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function runLogs(): HasMany
    {
        return $this->hasMany(CronRunLog::class);
    }
}
