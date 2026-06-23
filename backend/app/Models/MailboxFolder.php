<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxFolder extends Model
{
    protected $fillable = [
        'mailbox_account_id', 'external_folder_id', 'display_name', 'parent_external_folder_id',
        'parent_display_name', 'total_item_count', 'unread_item_count', 'is_sync_enabled',
        'is_order_folder', 'customer_id', 'trust_level', 'sync_priority', 'delta_token',
        'is_active', 'last_discovered_at', 'last_synced_at', 'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'is_sync_enabled' => 'boolean', 'is_order_folder' => 'boolean', 'is_active' => 'boolean',
            'last_discovered_at' => 'datetime', 'last_synced_at' => 'datetime',
        ];
    }

    public function mailboxAccount(): BelongsTo { return $this->belongsTo(MailboxAccount::class); }
    public function customer(): BelongsTo { return $this->belongsTo(AcumaticaCustomer::class); }
    public function rules(): HasMany { return $this->hasMany(MailboxRuleMapping::class); }
    public function emails(): HasMany { return $this->hasMany(Email::class); }
}
