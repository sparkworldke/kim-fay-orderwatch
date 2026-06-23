<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxRuleMapping extends Model
{
    protected $fillable = [
        'mailbox_account_id', 'mailbox_folder_id', 'existing_rule_name', 'customer_id',
        'is_enabled', 'is_trusted', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'is_trusted' => 'boolean'];
    }

    public function mailboxAccount(): BelongsTo { return $this->belongsTo(MailboxAccount::class); }
    public function folder(): BelongsTo { return $this->belongsTo(MailboxFolder::class, 'mailbox_folder_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(AcumaticaCustomer::class); }
}
