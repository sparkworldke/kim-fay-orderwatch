<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySlaConfig extends Model
{
    protected $table = 'delivery_sla_config';

    protected $fillable = [
        'region_key',
        'label',
        'sla_hours',
        'warning_hours',
        'breach_hours',
        'is_metro',
        'is_active',
        'alert_min_orders',
        'alert_delayed_pct',
        'clock_start',
    ];

    protected function casts(): array
    {
        return [
            'sla_hours' => 'integer',
            'warning_hours' => 'integer',
            'breach_hours' => 'integer',
            'is_metro' => 'boolean',
            'is_active' => 'boolean',
            'alert_min_orders' => 'integer',
            'alert_delayed_pct' => 'float',
        ];
    }

    public static function forRegionKey(string $regionKey): self
    {
        $normalized = strtolower(trim($regionKey));
        $config = self::query()
            ->where('region_key', $normalized)
            ->where('is_active', true)
            ->first();

        if ($config) {
            return $config;
        }

        return self::fallback($normalized);
    }

    public static function fallback(string $regionKey = 'other'): self
    {
        $config = new self;
        $config->region_key = $regionKey;
        $config->label = ucfirst($regionKey);
        $config->sla_hours = in_array($regionKey, ['nairobi', 'coast'], true) ? 24 : 72;
        $config->warning_hours = in_array($regionKey, ['nairobi', 'coast'], true) ? null : 48;
        $config->breach_hours = in_array($regionKey, ['nairobi', 'coast'], true) ? 24 : 72;
        $config->is_metro = in_array($regionKey, ['nairobi', 'coast'], true);
        $config->is_active = true;
        $config->alert_min_orders = 10;
        $config->alert_delayed_pct = 15.0;
        $config->clock_start = 'approved_at';

        return $config;
    }

    /** @return list<self> */
    public static function activeRules(): array
    {
        $rules = self::query()
            ->where('is_active', true)
            ->orderBy('region_key')
            ->get()
            ->all();

        return $rules !== [] ? $rules : [
            self::fallback('nairobi'),
            self::fallback('coast'),
            self::fallback('other'),
        ];
    }

    public static function regionKeyFromZoneRegion(?string $zoneRegion): string
    {
        $normalized = strtolower(trim((string) ($zoneRegion ?? '')));

        return match ($normalized) {
            'nairobi' => 'nairobi',
            'coast' => 'coast',
            default => 'other',
        };
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'region_key' => $this->region_key,
            'label' => $this->label,
            'sla_hours' => $this->sla_hours,
            'warning_hours' => $this->warning_hours,
            'breach_hours' => $this->breach_hours ?? $this->sla_hours,
            'is_metro' => $this->is_metro,
            'alert_min_orders' => $this->alert_min_orders,
            'alert_delayed_pct' => (float) $this->alert_delayed_pct,
            'clock_start' => $this->clock_start,
        ];
    }
}