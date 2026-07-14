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
        'URI', 'TOI', 'AIR', 'ALK', 'DIS', 'KLE',
    ];

    /** Partner / third-party brand prefixes (Trading). */
    private const TRADING_PREFIXES = [
        'DOV', 'REX', 'LUX', 'HUG', 'KOT', 'COW', 'APT', 'BIO',
        'DAB', 'ORS', 'VAT', 'HOB', 'DUR', 'FEM', 'MIS',
        'MSW', 'IKO', 'CON', 'BIG',
    ];

    public function classify(?string $inventoryId, ?string $productType = null): string
    {
        if ($productType === self::MANUFACTURED) {
            return self::MANUFACTURED;
        }

        if ($productType === self::TRADING) {
            return self::TRADING;
        }

        $upper = strtoupper(trim((string) $inventoryId));

        foreach (self::TRADING_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::TRADING;
            }
        }

        foreach (self::MANUFACTURED_PREFIXES as $prefix) {
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