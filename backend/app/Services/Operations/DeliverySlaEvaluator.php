<?php

namespace App\Services\Operations;

use App\Models\DeliverySlaConfig;
use Carbon\Carbon;

class DeliverySlaEvaluator
{
    public const METRO_SLA_HOURS = 24;

    public const REGIONAL_WARNING_HOURS = 48;

    public const REGIONAL_BREACH_HOURS = 72;

    public function isMetroZone(?string $zoneId, ?string $zoneDescription, ?string $zoneRegion = null): bool
    {
        $regionKey = $this->resolveRegionKey($zoneId, $zoneDescription, $zoneRegion);

        return DeliverySlaConfig::forRegionKey($regionKey)->is_metro;
    }

    public function resolveRegionKey(?string $zoneId, ?string $zoneDescription, ?string $zoneRegion = null): string
    {
        $normalized = strtolower(trim((string) ($zoneRegion ?? '')));
        if ($normalized !== '') {
            return DeliverySlaConfig::regionKeyFromZoneRegion($zoneRegion);
        }

        $haystack = strtolower(trim(($zoneId ?? '').' '.($zoneDescription ?? '')));
        if ($haystack === '') {
            return 'other';
        }

        if (str_contains($haystack, 'mombasa') || str_contains($haystack, 'coast') || str_contains($haystack, 'msa')) {
            return 'coast';
        }

        if (str_contains($haystack, 'nairobi') || str_contains($haystack, 'nairi')) {
            return 'nairobi';
        }

        return 'other';
    }

    /**
     * @return array{
     *   delivery_hours: float|null,
     *   sla_hours: int|null,
     *   sla_warning_hours: int|null,
     *   delivery_sla_status: string,
     *   delivery_sla_label: string,
     *   shipping_zone_id: string|null,
     *   shipping_zone_description: string|null,
     *   shipping_zone_region: string|null,
     *   region_key: string|null,
     *   is_metro_zone: bool
     * }
     */
    public function evaluate(
        ?Carbon $orderDate,
        ?Carbon $approvedAt,
        ?Carbon $shippedAt,
        ?Carbon $shipDate,
        ?string $zoneId,
        ?string $zoneDescription,
        ?Carbon $asOf = null,
        ?string $zoneRegion = null,
    ): array {
        $asOf ??= now();
        $regionKey = $this->resolveRegionKey($zoneId, $zoneDescription, $zoneRegion);
        $config = DeliverySlaConfig::forRegionKey($regionKey);
        $start = $this->resolveClockStart($orderDate, $approvedAt, $shipDate, $config->clock_start);

        if ($start === null) {
            return $this->unknownResult($zoneId, $zoneDescription, $zoneRegion, $regionKey, $config);
        }

        $end = $shippedAt ?? $shipDate ?? $asOf;
        $hours = $start->diffInMinutes($end) / 60;
        $breachHours = (int) ($config->breach_hours ?? $config->sla_hours);
        $warningHours = $config->warning_hours;

        if ($config->is_metro) {
            $sla = (int) $config->sla_hours;
            $status = $hours > $sla ? 'breach' : 'ok';
            $label = $status === 'breach'
                ? "Delivery took {$this->formatHours($hours)} — exceeds {$sla}h {$config->label} SLA"
                : "Within {$sla}h {$config->label} SLA";
        } else {
            $sla = $breachHours;
            $status = match (true) {
                $hours > $breachHours => 'breach',
                $warningHours !== null && $hours > $warningHours => 'warning',
                default => 'ok',
            };
            $label = match ($status) {
                'breach' => "Delivery took {$this->formatHours($hours)} — exceeds {$breachHours}h {$config->label} SLA",
                'warning' => "Delivery took {$this->formatHours($hours)} — exceeds {$warningHours}h {$config->label} SLA",
                default => "Within {$config->label} SLA ({$warningHours}–{$breachHours}h)",
            };
        }

        return [
            'delivery_hours' => round($hours, 1),
            'sla_hours' => $sla,
            'sla_warning_hours' => $config->is_metro ? null : $warningHours,
            'delivery_sla_status' => $status,
            'delivery_sla_label' => $label,
            'shipping_zone_id' => $zoneId,
            'shipping_zone_description' => $zoneDescription,
            'shipping_zone_region' => $zoneRegion,
            'region_key' => $regionKey,
            'is_metro_zone' => $config->is_metro,
        ];
    }

    /** @return array<string, mixed> */
    public function publicRules(): array
    {
        $rules = DeliverySlaConfig::activeRules();

        return [
            'clock_start' => $rules[0]->clock_start ?? 'approved_at',
            'clock_start_label' => $this->clockStartLabel($rules[0]->clock_start ?? 'approved_at'),
            'regions' => array_map(fn (DeliverySlaConfig $config) => $config->toPublicArray(), $rules),
            'metro_sla_hours' => DeliverySlaConfig::forRegionKey('nairobi')->sla_hours,
            'regional_warning_hours' => DeliverySlaConfig::forRegionKey('other')->warning_hours ?? self::REGIONAL_WARNING_HOURS,
            'regional_breach_hours' => DeliverySlaConfig::forRegionKey('other')->breach_hours ?? self::REGIONAL_BREACH_HOURS,
        ];
    }

    private function resolveClockStart(
        ?Carbon $orderDate,
        ?Carbon $approvedAt,
        ?Carbon $shipDate,
        string $clockStart,
    ): ?Carbon {
        return match ($clockStart) {
            'ship_date' => $shipDate ?? $approvedAt ?? $orderDate,
            'order_date' => $orderDate,
            default => $approvedAt ?? $orderDate,
        };
    }

    private function clockStartLabel(string $clockStart): string
    {
        return match ($clockStart) {
            'ship_date' => 'Ship date',
            'order_date' => 'Order date',
            default => 'Order approval',
        };
    }

    /** @return array<string, mixed> */
    private function unknownResult(
        ?string $zoneId,
        ?string $zoneDescription,
        ?string $zoneRegion,
        string $regionKey,
        DeliverySlaConfig $config,
    ): array {
        return [
            'delivery_hours' => null,
            'sla_hours' => null,
            'sla_warning_hours' => null,
            'delivery_sla_status' => 'unknown',
            'delivery_sla_label' => 'Order date unavailable',
            'shipping_zone_id' => $zoneId,
            'shipping_zone_description' => $zoneDescription,
            'shipping_zone_region' => $zoneRegion,
            'region_key' => $regionKey,
            'is_metro_zone' => $config->is_metro,
        ];
    }

    private function formatHours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 1), '0'), '.').'h';
    }
}