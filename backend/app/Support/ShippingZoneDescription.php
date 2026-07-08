<?php

namespace App\Support;

class ShippingZoneDescription
{
    /** @return array{name: string|null, region: string|null} */
    public static function metadataForId(?string $zoneId): array
    {
        $normalized = self::normalizeId($zoneId);
        if ($normalized === null) {
            return ['name' => null, 'region' => null];
        }

        $known = config('shipping_zones.known_zones', []);
        if (! is_array($known) || ! isset($known[$normalized]) || ! is_array($known[$normalized])) {
            return ['name' => null, 'region' => null];
        }

        $entry = $known[$normalized];

        return [
            'name' => isset($entry['name']) ? trim((string) $entry['name']) : null,
            'region' => isset($entry['region']) ? trim((string) $entry['region']) : null,
        ];
    }

    public static function nameForId(?string $zoneId): ?string
    {
        return self::metadataForId($zoneId)['name'] ?: null;
    }

    public static function regionForId(?string $zoneId): ?string
    {
        return self::metadataForId($zoneId)['region'] ?: null;
    }

    public static function forId(?string $zoneId): ?string
    {
        $name = self::nameForId($zoneId);
        $region = self::regionForId($zoneId);

        if ($name !== null && $region !== null) {
            return "{$name} ({$region})";
        }

        return $name ?? $region;
    }

    public static function resolve(?string $zoneId, mixed $acumaticaDescription): ?string
    {
        if (is_string($acumaticaDescription)) {
            $fromAcumatica = trim($acumaticaDescription);
            if ($fromAcumatica !== '') {
                return $fromAcumatica;
            }
        }

        return self::forId($zoneId);
    }

    /** @return array{description: string|null, name: string|null, region: string|null} */
    public static function resolveRecord(?string $zoneId, mixed $acumaticaDescription): array
    {
        $metadata = self::metadataForId($zoneId);

        return [
            'description' => self::resolve($zoneId, $acumaticaDescription),
            'name' => $metadata['name'],
            'region' => $metadata['region'],
        ];
    }

    private static function normalizeId(?string $zoneId): ?string
    {
        $normalized = strtoupper(trim((string) ($zoneId ?? '')));

        return $normalized !== '' ? $normalized : null;
    }
}