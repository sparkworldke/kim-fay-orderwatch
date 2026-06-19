<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaSyncLog extends Model
{
    protected $fillable = [
        'sync_type',
        'started_at',
        'ended_at',
        'record_count',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }
}
