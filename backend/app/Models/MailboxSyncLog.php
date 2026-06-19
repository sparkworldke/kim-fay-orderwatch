<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxSyncLog extends Model
{
    protected $fillable = [
        'mailbox_account_id',
        'started_at',
        'ended_at',
        'emails_fetched',
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

    public function mailboxAccount(): BelongsTo
    {
        return $this->belongsTo(MailboxAccount::class);
    }
}
