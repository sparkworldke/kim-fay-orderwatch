<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRule extends Model
{
    protected $fillable = [
        'rule_key',
        'label',
        'channels',
        'is_enabled',
        'last_evaluated_at',
        'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'channels'           => 'array',
            'is_enabled'         => 'boolean',
            'last_evaluated_at'  => 'datetime',
            'last_triggered_at'  => 'datetime',
        ];
    }

    public function dispatchLogs(): HasMany
    {
        return $this->hasMany(NotificationDispatchLog::class, 'rule_id');
    }

    public function emailRecipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_rule_email_recipients');
    }

    public function roleRecipients(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'notification_rule_role_recipients');
    }

    /** @return list<string> */
    public function configuredRecipientEmails(): array
    {
        $direct = $this->emailRecipients()
            ->where('is_active', true)
            ->pluck('email')
            ->all();

        $roles = $this->roleRecipients()->pluck('roles.name')->all();
        if ($roles === []) {
            return array_values(array_unique(array_filter($direct)));
        }

        $roleEmails = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($roles) {
                $query->whereIn('role', $roles)
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', $roles));
            })
            ->pluck('email')
            ->all();

        return array_values(array_unique(array_filter([...$direct, ...$roleEmails])));
    }
}
