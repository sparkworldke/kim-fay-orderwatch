<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailFilter extends Model
{
    protected $fillable = [
        'name',
        'conditions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // No 'array' cast for conditions — we manage JSON manually via
            // getConditionsAttribute / setConditionsAttribute to avoid
            // Eloquent double-encoding the JSON string during INSERT.
        ];
    }

    public function getConditionsAttribute(): array
    {
        $raw = $this->attributes['conditions'] ?? null;
        if (is_null($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setConditionsAttribute(array|string|null $value): void
    {
        if (is_array($value)) {
            $this->attributes['conditions'] = json_encode(array_values($value));
        } elseif (is_string($value) && $value !== '') {
            // Accept a pre-encoded JSON string (legacy path)
            $this->attributes['conditions'] = $value;
        } else {
            $this->attributes['conditions'] = json_encode([]);
        }
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(MailboxSyncLog::class);
    }
}
