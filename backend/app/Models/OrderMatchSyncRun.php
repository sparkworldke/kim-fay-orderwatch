<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderMatchSyncRun extends Model
{
    protected $fillable = [
        'mailbox_folder_id',
        'sync_from',
        'sync_to',
        'emails_found',
        'emails_created',
        'emails_updated',
        'emails_queued',
        'status',
        'triggered_by_user_id',
        'started_at',
        'ended_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'sync_from'  => 'date',
            'sync_to'    => 'date',
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class, 'mailbox_folder_id');
    }

    public function storedEmails(): HasMany
    {
        return $this->hasMany(OrderMatchSyncRunEmail::class, 'order_match_sync_run_id');
    }
}