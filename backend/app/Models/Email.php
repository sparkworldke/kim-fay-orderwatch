<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Email extends Model
{
    protected $fillable = [
        'mailbox_account_id',
        'message_id',
        'subject',
        'from_email',
        'from_name',
        'to_recipients',
        'body_preview',
        'is_read',
        'received_at',
        'folder',
        'extracted_po_number',
        'po_extraction_method',
        'po_extraction_confidence',
        'matched_order_id',
        'has_attachments',
        'po_extraction_attempted',
    ];

    protected function casts(): array
    {
        return [
            'to_recipients'            => 'array',
            'is_read'                  => 'boolean',
            'has_attachments'          => 'boolean',
            'po_extraction_attempted'  => 'boolean',
            'received_at'              => 'datetime',
        ];
    }

    public function mailboxAccount(): BelongsTo
    {
        return $this->belongsTo(MailboxAccount::class);
    }
}
