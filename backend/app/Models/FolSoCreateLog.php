<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolSoCreateLog extends Model
{
    protected $fillable = [
        'fol_request_id',
        'public_ref',
        'customer_acumatica_id',
        'attempt_source',
        'status',
        'acumatica_order_nbr',
        'error_message',
        'payload_json',
        'actor_user_id',
        'cron_run_log_id',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FolRequest::class, 'fol_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
