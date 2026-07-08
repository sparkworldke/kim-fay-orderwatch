<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AcumaticaSyncLog extends Model
{
    public const ACTIVE_WINDOW_MINUTES = 2;

    public const MAX_RUNNING_MINUTES = 120;

    protected $fillable = [
        'sync_type',
        'cron_run_log_id',
        'started_at',
        'ended_at',
        'heartbeat_at',
        'stop_requested_at',
        'record_count',
        'success_count',
        'failed_count',
        'status',
        'error_message',
        'filters',
        'trigger_type',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at'        => 'datetime',
            'ended_at'          => 'datetime',
            'heartbeat_at'      => 'datetime',
            'stop_requested_at' => 'datetime',
            'filters'           => 'array',
        ];
    }

    public function scopeActiveRunning(Builder $query): Builder
    {
        $threshold = now()->subMinutes(self::ACTIVE_WINDOW_MINUTES);

        return $query
            ->where('status', 'running')
            ->whereNull('ended_at')
            ->where(function (Builder $builder) use ($threshold) {
                $builder->where('heartbeat_at', '>=', $threshold)
                    ->orWhere(function (Builder $fallback) use ($threshold) {
                        $fallback->whereNull('heartbeat_at')
                            ->where('started_at', '>=', $threshold);
                    });
            });
    }

    public static function failStaleRunning(array $syncTypes, ?string $message = null): int
    {
        $threshold = now()->subMinutes(self::ACTIVE_WINDOW_MINUTES);

        return static::query()
            ->whereIn('sync_type', $syncTypes)
            ->where('status', 'running')
            ->whereNull('ended_at')
            ->where(function (Builder $builder) use ($threshold) {
                $builder->where('heartbeat_at', '<', $threshold)
                    ->orWhere(function (Builder $fallback) use ($threshold) {
                        $fallback->whereNull('heartbeat_at')
                            ->where('started_at', '<', $threshold);
                    });
            })
            ->update([
                'status'        => 'failed',
                'ended_at'      => now(),
                'error_message' => $message ?? 'Sync ended unexpectedly after losing its runtime heartbeat.',
            ]);
    }

    public static function failLongRunning(array $syncTypes, ?string $message = null): int
    {
        $threshold = now()->subMinutes(self::MAX_RUNNING_MINUTES);

        return static::query()
            ->whereIn('sync_type', $syncTypes)
            ->where('status', 'running')
            ->whereNull('ended_at')
            ->where('started_at', '<', $threshold)
            ->update([
                'status'        => 'failed',
                'ended_at'      => now(),
                'error_message' => $message ?? 'Sync exceeded the maximum allowed runtime and was stopped automatically.',
            ]);
    }

    public function markHeartbeat(): void
    {
        if ($this->status !== 'running') {
            return;
        }

        $now = now();

        $this->forceFill(['heartbeat_at' => $now]);
        $this->save();
        $this->setAttribute('heartbeat_at', $now);
    }

    public function requestStop(): void
    {
        if ($this->status !== 'running') {
            return;
        }

        $now = now();

        $this->forceFill(['stop_requested_at' => $now]);
        $this->save();
        $this->setAttribute('stop_requested_at', $now);
    }

    public function isActivelyRunning(): bool
    {
        if ($this->status !== 'running' || $this->ended_at !== null) {
            return false;
        }

        $threshold = now()->subMinutes(self::ACTIVE_WINDOW_MINUTES);
        $lastPulse = $this->heartbeat_at ?? $this->started_at;

        return $lastPulse !== null && $lastPulse->gte($threshold);
    }
}
