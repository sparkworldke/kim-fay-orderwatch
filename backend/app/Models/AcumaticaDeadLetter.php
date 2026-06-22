<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaDeadLetter extends Model
{
    protected $fillable = [
        'sync_run_id',
        'resource_type',
        'resource_id',
        'attempt_count',
        'last_error',
        'raw_payload',
        'remediation_notes',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }
}
