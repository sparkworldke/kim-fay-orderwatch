<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxSyncItemLog extends Model
{
    protected $fillable = [
        'mailbox_sync_log_id',
        'mailbox_folder_id',
        'email_id',
        'message_id',
        'outcome',
        'reason',
        'decision_source',
        'po_number_detected',
        'po_number_source',
        'decision_context',
        'attempts',
        'duration_ms',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'po_number_detected' => 'boolean',
            'decision_context' => 'array',
        ];
    }

    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(MailboxSyncLog::class, 'mailbox_sync_log_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class, 'mailbox_folder_id');
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
