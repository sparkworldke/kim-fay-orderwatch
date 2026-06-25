<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    protected $fillable = [
        'mailbox_account_id',
        'mailbox_folder_id',
        'external_folder_id',
        'message_id',
        'subject',
        'from_email',
        'from_name',
        'to_recipients',
        'body_preview',
        'body_content',
        'conversation_id',
        'internet_message_id',
        'is_read',
        'received_at',
        'folder',
        'ingestion_classification',
        'ingestion_reason_codes',
        'ingestion_decision_sources',
        'ingestion_review_status',
        'ingestion_review_reason',
        'ingestion_reviewed_by',
        'ingestion_reviewed_at',
        'extracted_po_number',
        'canonical_po',
        'po_extraction_method',
        'po_extraction_confidence',
        'extraction_status',
        'match_status',
        'duplicate_flag',
        'canonical_email_id',
        'matched_order_id',
        'has_attachments',
        'po_extraction_attempted',
        'match_classification',
        'match_sources',
        'match_evidence',
        'match_conflicts',
        'match_reason_codes',
        'match_rule_version',
        'reviewer_decision',
        'reviewer_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'to_recipients'            => 'array',
            'is_read'                  => 'boolean',
            'has_attachments'          => 'boolean',
            'po_extraction_attempted'  => 'boolean',
            'received_at'              => 'datetime',
            'match_sources'            => 'array',
            'match_evidence'           => 'array',
            'match_conflicts'          => 'array',
            'match_reason_codes'       => 'array',
            'reviewed_at'              => 'datetime',
            'ingestion_reason_codes'   => 'array',
            'ingestion_decision_sources' => 'array',
            'ingestion_reviewed_at'    => 'datetime',
        ];
    }

    public function mailboxAccount(): BelongsTo
    {
        return $this->belongsTo(MailboxAccount::class);
    }

    public function mailboxFolder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function matchedOrder(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'matched_order_id');
    }

    public function matchAttempts(): HasMany
    {
        return $this->hasMany(EmailMatchAttempt::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(MatchPrediction::class);
    }

    public function matchLogs(): HasMany
    {
        return $this->hasMany(MatchLog::class);
    }
}
