<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderMatchRun extends Model
{
    protected $fillable = [
        'triggered_by_user_id',
        'started_at',
        'ended_at',
        'status',
        'emails_processed',
        'po_extracted',
        'matched',
        'unmatched',
        'duplicate',
        'missing_in_acumatica',
        'error_message',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
            'summary'    => 'array',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
