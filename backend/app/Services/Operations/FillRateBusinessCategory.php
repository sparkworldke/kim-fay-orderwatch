<?php

namespace App\Services\Operations;

/**
 * Classifies inventory into the two core fill-rate business categories:
 * Manufactured goods vs Trading (Partners) goods.
 */
class FillRateBusinessCategory
{
    public const MANUFACTURED = 'manufactured';

    public const TRADING = 'trading';

    public const LABEL_MANUFACTURED = 'Manufactured';

    public const LABEL_TRADING = 'Trading (Partners)';

    /** Inventory ID prefixes classified as Kim-Fay manufactured products. */
    private const MANUFACTURED_PREFIXES = [
        'FAY', 'SIF', 'COS', 'TIS', 'ULT', 'STD', 'SHO', 'ANT',
        'URI', 'TOI', 'ALK', 'DIS', 'KLE', 'HYG', 'KIM',
    ];

    /** Partner / third-party brand prefixes (Trading). Longer codes first (COWGT, APTML). */
    private const TRADING_PREFIXES = [
        'COWGT', 'APTML', 'CONPA', 'DOVBW',
        'VATOL', 'VATSH', 'VATCN', 'VATCR', 'HOBBW', 'AIROM',
        'DOV', 'REX', 'LUX', 'HUG', 'KOT', 'COW', 'APT', 'BIO',
        'DAB', 'ORS', 'VAT', 'HOB', 'AIR', 'DUR', 'FEM', 'MIS',
        'MSW', 'IKO', 'CON', 'BIG',
    ];

    public function classify(?string $inventoryId, ?string $productType = null): string
    {
        $normalizedType = strtolower(trim((string) $productType));
        if (in_array($normalizedType, [self::MANUFACTURED, 'manufacture', 'own brand', 'own_brand'], true)) {
            return self::MANUFACTURED;
        }

        if (in_array($normalizedType, [self::TRADING, 'partner', 'partners', 'third party', 'third_party'], true)) {
            return self::TRADING;
        }

        $upper = strtoupper(trim((string) $inventoryId));

        // Longest trading prefixes first (COWGT before COW; APTML before APT)
        // so partner codes are not mis-classified vs manufactured (e.g. COW vs COS).
        $trading = self::TRADING_PREFIXES;
        usort($trading, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($trading as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::TRADING;
            }
        }

        $manufactured = self::MANUFACTURED_PREFIXES;
        usort($manufactured, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($manufactured as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::MANUFACTURED;
            }
        }

        return self::TRADING;
    }

    public function label(string $category): string
    {
        return $category === self::MANUFACTURED
            ? self::LABEL_MANUFACTURED
            : self::LABEL_TRADING;
    }
}