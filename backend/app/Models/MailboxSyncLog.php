<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxSyncLog extends Model
{
    protected $fillable = [
        'mailbox_account_id',
        'cron_run_log_id',
        'email_filter_id',
        'started_at',
        'ended_at',
        'emails_fetched',
        'emails_created',
        'emails_updated',
        'emails_skipped',
        'emails_deleted',
        'emails_failed',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function mailboxAccount(): BelongsTo
    {
        return $this->belongsTo(MailboxAccount::class);
    }

    public function itemLogs(): HasMany
    {
        return $this->hasMany(MailboxSyncItemLog::class);
    }

    public function emailFilter(): BelongsTo
    {
        return $this->belongsTo(EmailFilter::class);
    }
}
