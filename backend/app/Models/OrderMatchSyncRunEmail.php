<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderMatchSyncRunEmail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_match_sync_run_id',
        'email_id',
        'outcome',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(OrderMatchSyncRun::class, 'order_match_sync_run_id');
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}