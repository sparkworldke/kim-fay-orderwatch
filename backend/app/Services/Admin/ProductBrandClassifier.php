<?php

namespace App\Services\Admin;

class ProductBrandClassifier
{
    /**
     * Brands Kim-Fay distributes for other manufacturers ("trading" products),
     * as opposed to Kim-Fay's own manufactured lines. Matched case-insensitively
     * against the item description and inventory ID.
     */
    private const TRADING_BRAND_PATTERNS = [
        'Huggies'   => '/\bHuggies\b/i',
        'Kotex'     => '/\bKotex\b/i',
        'Vatika'    => '/\bVatika\b/i',
        'Dabur'     => '/\bDabur\b/i',
        'Miswak'    => '/\bMiswak\b/i',
        'Bio-Oil'   => '/\bBio[\s-]?Oil\b/i',
        'Duracell'  => '/\bDuracell\b/i',
        'Dove'      => '/\bDove\b/i',
        'Lux'       => '/\bLux\b/i',
        'Rexona'    => '/\bRexona\b/i',
        'Fem'       => '/\bFem\b/i',
        'Hobby'     => '/\bHobby\b/i',
        'ORS'       => '/\bORS\b/i',
        'Dermoviva' => '/\bDermoviva\b/i',
    ];

    /**
     * @return array{brand: ?string, product_type: string}
     */
    public function classify(?string $description, ?string $inventoryId = null): array
    {
        $haystack = trim(($description ?? '').' '.($inventoryId ?? ''));

        if ($haystack !== '') {
            foreach (self::TRADING_BRAND_PATTERNS as $brand => $pattern) {
                if (preg_match($pattern, $haystack) === 1) {
                    return ['brand' => $brand, 'product_type' => 'trading'];
                }
            }
        }

        return ['brand' => null, 'product_type' => 'manufactured'];
    }
}
