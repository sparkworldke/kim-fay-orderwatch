<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'role',
        'phone_number',
        'rep_code',
        'is_active',
        'is_super_admin',
        'is_account_manager',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'is_super_admin'    => 'boolean',
            'is_account_manager'=> 'boolean',
        ];
    }

    public function scopeEligibleForOtp(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('email_verified_at');
    }

    public function isEligibleForOtp(): bool
    {
        return $this->is_active && $this->email_verified_at !== null;
    }

    public function otps(): HasMany
    {
        return $this->hasMany(Otp::class);
    }

    public function signInLogs(): HasMany
    {
        return $this->hasMany(SignInLog::class);
    }

    public function repCodeHistory(): HasMany
    {
        return $this->hasMany(UserRepCodeHistory::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_super_admin || $this->role === 'Administrator') {
            return true;
        }

        return Permission::query()
            ->where('name', $permission)
            ->whereHas('roles.userRoles', fn (Builder $query) => $query->where('user_id', $this->id))
            ->exists();
    }
}
