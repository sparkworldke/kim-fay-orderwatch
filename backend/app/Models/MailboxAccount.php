<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailboxAccount extends Model
{
    protected $fillable = [
        'email',
        'display_name',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'token_expires_at',
        'status',
        'last_synced_at',
        'delta_token',
        'sync_from_date',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_synced_at'   => 'datetime',
            'sync_from_date'   => 'date',
        ];
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(MailboxSyncLog::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(MailboxFolder::class);
    }

    public function ruleMappings(): HasMany
    {
        return $this->hasMany(MailboxRuleMapping::class);
    }
}
