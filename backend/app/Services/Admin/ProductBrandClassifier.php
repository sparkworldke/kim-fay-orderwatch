<?php

namespace App\Services\Admin;

class ProductBrandClassifier
{
    /**
     * Official Kimfay (manufactured) brands shown in Brand filters.
     * Order is the display order in the cascade dropdown.
     *
     * @var list<string>
     */
    public const KIMFAY_BRANDS = [
        'Kimfay',
        'Fay',
        'Sifa',
        'Kleenex',
        'Cosy',
        'Cosy Poa',
        'Tishu Poa',
        'Ultra Clean',
    ];

    /**
     * Official partner (trading) brands always shown under Partner Brands.
     * Matches brands.md + Products-With Brands.csv.
     *
     * @var list<string>
     */
    public const PARTNER_BRANDS = [
        'Airoma',
        'Aptamil',
        'Bio Oil',
        'Cow & Gate',
        'Dabur',
        'Dermoviva',
        'Dove',
        'Duracell',
        'Fem',
        'Hobby',
        'Huggies',
        'Kotex',
        'Lux',
        'Miswak',
        'Ors',
        'Rexona',
        'Vatika',
        // Extra partner lines still in use
        'Ikko',
        'Confidence',
    ];

    /**
     * Brands Kim-Fay distributes for other manufacturers ("trading" products),
     * as opposed to Kim-Fay's own manufactured lines. Matched case-insensitively
     * against the item description and inventory ID.
     */
    private const TRADING_BRAND_PATTERNS = [
        'Cow & Gate'  => '/\bCow\s*&\s*Gate\b|\bCow\s+and\s+Gate\b|\bCow&Gate\b/i',
        'Aptamil'     => '/\bAptamil\b/i',
        'Confidence'  => '/\bConfidence\b/i',
        'Ikko'        => '/\bIkko\b|\bIKKO\b/i',
        'Huggies'     => '/\bHuggies\b/i',
        'Kotex'       => '/\bKotex\b/i',
        'Vatika'      => '/\bVatika\b/i',
        'Dabur'       => '/\bDabur\b/i',
        'Miswak'      => '/\bMiswak\b/i',
        'Bio Oil'     => '/\bBio[\s-]?Oil\b/i',
        'Airoma'      => '/\bAiroma\b/i',
        'Duracell'    => '/\bDuracell\b/i',
        'Dove'        => '/\bDove\b/i',
        'Lux'         => '/\bLux\b/i',
        'Rexona'      => '/\bRexona\b/i',
        'Fem'         => '/\bFem\b/i',
        'Hobby'       => '/\bHobby\b/i',
        'Ors'         => '/\bORS\b/i',
        'Dermoviva'   => '/\bDermoviva\b/i',
    ];

    /**
     * Free-text aliases for partner brands (lowercase => canonical).
     *
     * @var array<string, string>
     */
    private const PARTNER_BRAND_ALIASES = [
        'cow & gate' => 'Cow & Gate',
        'cow and gate' => 'Cow & Gate',
        'cow&gate' => 'Cow & Gate',
        'cow& gate' => 'Cow & Gate',
        'cow &gate' => 'Cow & Gate',
        'c&g' => 'Cow & Gate',
        'aptamil' => 'Aptamil',
        'confidence' => 'Confidence',
        'ikko' => 'Ikko',
        'bio-oil' => 'Bio Oil',
        'bio oil' => 'Bio Oil',
        'biooil' => 'Bio Oil',
        'airoma' => 'Airoma',
        'huggies' => 'Huggies',
        'kotex' => 'Kotex',
        'vatika' => 'Vatika',
        'dabur' => 'Dabur',
        'miswak' => 'Miswak',
        'duracell' => 'Duracell',
        'dove' => 'Dove',
        'lux' => 'Lux',
        'rexona' => 'Rexona',
        'fem' => 'Fem',
        'hobby' => 'Hobby',
        'ors' => 'Ors',
        'dermoviva' => 'Dermoviva',
    ];

    /**
     * Inventory ID prefixes → manufactured brand display names.
     * Aligned with Acumatica Stock Items / brands.md open-SKU list.
     *
     * @var array<string, string>
     */
    private const MANUFACTURED_PREFIX_BRANDS = [
        'KIM' => 'Kimfay',
        'FAY' => 'Fay',
        'SIF' => 'Sifa',
        'KLE' => 'Kleenex',
        'KLN' => 'Kleenex',
        'COS' => 'Cosy', // Cosy Poa refined via description when present
        'TIS' => 'Tishu Poa',
        'ULT' => 'Ultra Clean',
        'ULC' => 'Ultra Clean',
        'HYG' => 'Hygiene',
        'DIS' => 'Dispenser',
        'ALK' => 'Alkaline',
        'ANT' => 'Anti-bacterial',
        'STD' => 'Standard',
        'TOI' => 'Toilet care',
        'URI' => 'Urinal',
        'SHO' => 'Sho',
    ];

    /**
     * Description patterns for Kimfay brands — longer / more specific first.
     *
     * @var array<string, string>
     */
    private const MANUFACTURED_BRAND_PATTERNS = [
        'Cosy Poa'    => '/\bCosy\s*Poa\b/i',
        'Tishu Poa'   => '/\bTishu\s*Poa\b|\bTissue\s*Poa\b/i',
        'Ultra Clean' => '/\bUltra\s*Clean\b|\bUltraclean\b/i',
        'Kleenex'     => '/\bKleenex\b|\bKleneex\b|\bKleeneex\b/i',
        'Hygiene'     => '/\bHygiene\b/i',
        'Kimfay'      => '/\bKim[\s-]?Fay\b|\bKimfay\b/i',
        'Cosy'        => '/\bCosy\b/i',
        'Sifa'        => '/\bSifa\b/i',
        'Fay'         => '/\bFay\b/i',
    ];

    /**
     * Map free-text / master brand strings onto the Kimfay allowlist.
     * Longer aliases first so "Cosy Poa" is not collapsed to "Cosy".
     *
     * @var array<string, string> lowercase needle => canonical
     */
    private const KIMFAY_BRAND_ALIASES = [
        'cosy poa' => 'Cosy Poa',
        'cosypoa' => 'Cosy Poa',
        'tishu poa' => 'Tishu Poa',
        'tissue poa' => 'Tishu Poa',
        'tishupoa' => 'Tishu Poa',
        'ultra clean' => 'Ultra Clean',
        'ultraclean' => 'Ultra Clean',
        'ultimate' => 'Ultra Clean',
        'kleneex' => 'Kleenex',
        'kleenex' => 'Kleenex',
        'kleeneex' => 'Kleenex',
        'klenex' => 'Kleenex',
        'kim-fay' => 'Kimfay',
        'kim fay' => 'Kimfay',
        'kimfay' => 'Kimfay',
        'kimfay brand' => 'Kimfay',
        'cosy' => 'Cosy',
        'sifa' => 'Sifa',
        'fay' => 'Fay',
        'fay tissues' => 'Fay',
        'fay tissue' => 'Fay',
    ];

    /**
     * Trading inventory ID prefixes (longer codes first so COWGT / APTML win
     * before short COW / APT fallbacks; COW must still beat manufactured COS).
     *
     * @var array<string, string>
     */
    private const TRADING_PREFIX_BRANDS = [
        // Exact Acumatica inventory prefixes (from brands.md / stock master)
        'COWGT' => 'Cow & Gate',
        'APTML' => 'Aptamil',
        'CONPA' => 'Confidence',
        'DOVBW' => 'Dove',
        'VATOL' => 'Vatika', // hair oil
        'VATSH' => 'Vatika', // shampoo
        'VATCN' => 'Vatika', // conditioner
        'VATCR' => 'Vatika', // cream
        'HOBBW' => 'Hobby',  // body wash
        'AIROM' => 'Airoma',
        'DOV' => 'Dove',
        'IKO' => 'Ikko',
        'REX' => 'Rexona',
        'LUX' => 'Lux',
        'HUG' => 'Huggies',
        'KOT' => 'Kotex',
        // Short fallbacks
        'COW' => 'Cow & Gate',
        'APT' => 'Aptamil',
        'CON' => 'Confidence',
        'BIO' => 'Bio Oil',
        'DAB' => 'Dabur',
        'ORS' => 'Ors',
        'VAT' => 'Vatika',
        'HOB' => 'Hobby',
        'AIR' => 'Airoma',
        'DUR' => 'Duracell',
        'FEM' => 'Fem',
        'MIS' => 'Miswak',
        'MSW' => 'Miswak',
    ];

    /**
     * @return list<string>
     */
    public function kimfayBrandAllowlist(): array
    {
        return self::KIMFAY_BRANDS;
    }

    /**
     * @return list<string>
     */
    public function partnerBrandAllowlist(): array
    {
        return self::PARTNER_BRANDS;
    }

    public function isKimfayBrand(?string $brand): bool
    {
        return $this->normalizeKimfayBrand($brand) !== null;
    }

    public function isPartnerBrand(?string $brand): bool
    {
        return $this->normalizePartnerBrand($brand) !== null;
    }

    /**
     * Map free-text onto the official partner list (Aptamil, Cow & Gate, …).
     */
    public function normalizePartnerBrand(?string $brand): ?string
    {
        $raw = is_string($brand) ? trim($brand) : '';
        if ($raw === '') {
            return null;
        }

        $lower = strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        $lower = str_replace([' and ', ' & '], ' & ', $lower);
        // Normalize "cow&gate" style.
        $compact = strtolower(preg_replace('/\s+/', '', $raw) ?? $raw);

        if (isset(self::PARTNER_BRAND_ALIASES[$lower])) {
            return self::PARTNER_BRAND_ALIASES[$lower];
        }
        if ($compact === 'cow&gate') {
            return 'Cow & Gate';
        }

        foreach (self::PARTNER_BRAND_ALIASES as $alias => $canonical) {
            if (str_starts_with($lower, $alias) || str_contains($lower, $alias)) {
                return $canonical;
            }
        }

        foreach (self::PARTNER_BRANDS as $canonical) {
            if (strcasecmp($canonical, $raw) === 0) {
                return $canonical;
            }
        }

        return null;
    }

    /** True when inventory ID starts with a known partner/trading prefix (COW, APT, …). */
    public function isTradingInventoryId(?string $inventoryId): bool
    {
        return $this->tradingPrefixMatch($inventoryId) !== null;
    }

    private function tradingPrefixMatch(?string $inventoryId): ?string
    {
        $upper = strtoupper(trim((string) $inventoryId));
        if ($upper === '') {
            return null;
        }
        // Match longest prefix first (COWGT before COW, APTML before APT).
        $prefixes = array_keys(self::TRADING_PREFIX_BRANDS);
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::TRADING_PREFIX_BRANDS[$prefix];
            }
        }

        return null;
    }

    /**
     * Map a free-text brand onto the official Kimfay list, or null if not one of them.
     */
    public function normalizeKimfayBrand(?string $brand): ?string
    {
        $raw = is_string($brand) ? trim($brand) : '';
        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);
        if (isset(self::KIMFAY_BRAND_ALIASES[$lower])) {
            return self::KIMFAY_BRAND_ALIASES[$lower];
        }

        // Starts-with / contains for master values like "Fay Toilet", "Cosy Poa Soft".
        foreach (self::KIMFAY_BRAND_ALIASES as $alias => $canonical) {
            if (str_starts_with($lower, $alias) || str_contains($lower, $alias)) {
                // Prefer longest alias match (aliases map is ordered longer-first for multi-word).
                // Skip short false positives: bare "fay" inside unrelated words is unlikely.
                return $canonical;
            }
        }

        foreach (self::KIMFAY_BRANDS as $canonical) {
            if (strcasecmp($canonical, $raw) === 0) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Catalogue / Production Intel ownership: manufactured | partner.
     * Partner covers trading brands (product_type "trading" on inventory).
     */
    public function ownershipFromBrand(?string $brand, ?string $inventoryId = null, ?string $description = null): ?string
    {
        if ($this->normalizeKimfayBrand($brand) !== null) {
            return 'manufactured';
        }
        if ($this->normalizePartnerBrand($brand) !== null || $this->isTradingInventoryId($inventoryId)) {
            return 'partner';
        }

        $classified = $this->classify($description ?? $brand, $inventoryId);
        $type = strtolower((string) ($classified['product_type'] ?? ''));

        return match ($type) {
            'manufactured' => 'manufactured',
            'trading', 'partner' => 'partner',
            default => null,
        };
    }

    /**
     * Inventory product_type values: manufactured | trading.
     */
    public function productTypeFromOwnership(?string $ownership): string
    {
        return match (strtolower((string) $ownership)) {
            'partner', 'trading' => 'trading',
            default => 'manufactured',
        };
    }

    /**
     * @return array{brand: ?string, product_type: string}
     */
    public function classify(?string $description, ?string $inventoryId = null): array
    {
        // Inventory ID prefix is authoritative (e.g. HOBBW* = Hobby even when the
        // description mentions a free Vatika sample; VATOL* = Vatika oils).
        $fromTradingPrefix = $this->tradingPrefixMatch($inventoryId);
        if ($fromTradingPrefix !== null) {
            return ['brand' => $fromTradingPrefix, 'product_type' => 'trading'];
        }

        $haystack = trim((string) ($description ?? ''));

        // Manufactured description patterns before short manufactured prefixes so
        // "Cosy Poa …" wins over COS → Cosy.
        if ($haystack !== '') {
            foreach (self::MANUFACTURED_BRAND_PATTERNS as $brand => $pattern) {
                if (preg_match($pattern, $haystack) === 1) {
                    return ['brand' => $brand, 'product_type' => 'manufactured'];
                }
            }
        }

        $fromMfgPrefix = null;
        $upper = strtoupper(trim((string) $inventoryId));
        if ($upper !== '') {
            $mfgPrefixes = array_keys(self::MANUFACTURED_PREFIX_BRANDS);
            usort($mfgPrefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($mfgPrefixes as $prefix) {
                if (str_starts_with($upper, $prefix)) {
                    $fromMfgPrefix = self::MANUFACTURED_PREFIX_BRANDS[$prefix];
                    break;
                }
            }
        }
        if ($fromMfgPrefix !== null) {
            return ['brand' => $fromMfgPrefix, 'product_type' => 'manufactured'];
        }

        // Description-only trading patterns (when inventory ID has no known prefix).
        $tradeHaystack = trim($haystack.' '.(string) $inventoryId);
        if ($tradeHaystack !== '') {
            foreach (self::TRADING_BRAND_PATTERNS as $brand => $pattern) {
                if (preg_match($pattern, $tradeHaystack) === 1) {
                    return ['brand' => $brand, 'product_type' => 'trading'];
                }
            }
        }

        return ['brand' => null, 'product_type' => 'manufactured'];
    }

    /**
     * Resolve a display brand from the inventory ID prefix map (fallback when master brand is empty).
     */
    public function brandFromInventoryId(?string $inventoryId): ?string
    {
        $fromTrading = $this->tradingPrefixMatch($inventoryId);
        if ($fromTrading !== null) {
            return $fromTrading;
        }

        $upper = strtoupper(trim((string) $inventoryId));
        if ($upper === '') {
            return null;
        }

        $mfgPrefixes = array_keys(self::MANUFACTURED_PREFIX_BRANDS);
        usort($mfgPrefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($mfgPrefixes as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::MANUFACTURED_PREFIX_BRANDS[$prefix];
            }
        }

        return null;
    }

    /**
     * Canonical product_type for an inventory ID using prefix maps.
     */
    public function productTypeForInventoryId(?string $inventoryId): string
    {
        if ($this->tradingPrefixMatch($inventoryId) !== null) {
            return 'trading';
        }

        $upper = strtoupper(trim((string) $inventoryId));
        if ($upper === '') {
            return 'manufactured';
        }

        $mfgPrefixes = array_keys(self::MANUFACTURED_PREFIX_BRANDS);
        usort($mfgPrefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($mfgPrefixes as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'manufactured';
            }
        }

        return 'manufactured';
    }

    /**
     * Effective brand for reports/filters/display: prefer stored master brand
     * (normalized), else description/prefix classification.
     *
     * Returns any resolved brand name (not only cascade allowlists) so dashboard
     * rollups do not collapse known prefixes into "Unclassified".
     */
    public function resolveBrand(?string $storedBrand, ?string $inventoryId = null, ?string $description = null): ?string
    {
        // Trading inventory prefixes win over a wrong stored manufactured brand.
        $fromTradingPrefix = $this->tradingPrefixMatch($inventoryId);
        if ($fromTradingPrefix !== null) {
            return $fromTradingPrefix;
        }

        $partnerStored = $this->normalizePartnerBrand($storedBrand);
        if ($partnerStored !== null) {
            return $partnerStored;
        }

        $normalized = $this->normalizeKimfayBrand($storedBrand);
        if ($normalized !== null) {
            return $normalized;
        }

        $stored = is_string($storedBrand) ? trim($storedBrand) : '';
        $classified = $this->classify($description, $inventoryId);
        $fromClass = is_string($classified['brand'] ?? null) ? trim((string) $classified['brand']) : '';

        if ($fromClass !== '') {
            return $this->normalizePartnerBrand($fromClass)
                ?? $this->normalizeKimfayBrand($fromClass)
                ?? $fromClass;
        }

        // Prefix-only manufactured brands (Hygiene, Airoma, Dispenser, …).
        $fromMfgPrefix = $this->brandFromInventoryId($inventoryId);
        if ($fromMfgPrefix !== null && $fromMfgPrefix !== '') {
            return $this->normalizeKimfayBrand($fromMfgPrefix) ?? $fromMfgPrefix;
        }

        return $stored !== '' ? $stored : null;
    }

    /**
     * Inventory ID prefixes that map to the given brand name (case-insensitive).
     *
     * @return list<string>
     */
    public function prefixesForBrand(string $brand): array
    {
        $canonical = $this->normalizePartnerBrand($brand)
            ?? $this->normalizeKimfayBrand($brand)
            ?? trim($brand);
        $needle = strtolower($canonical);
        if ($needle === '') {
            return [];
        }

        $prefixes = [];
        foreach (array_merge(self::MANUFACTURED_PREFIX_BRANDS, self::TRADING_PREFIX_BRANDS) as $prefix => $name) {
            if (strtolower($name) === $needle) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * All known partner inventory-ID prefixes (APT, COW, DOV, …).
     *
     * @return list<string>
     */
    public function allTradingPrefixes(): array
    {
        return array_keys(self::TRADING_PREFIX_BRANDS);
    }

    /**
     * All known Kimfay inventory-ID prefixes (FAY, SIF, …).
     *
     * @return list<string>
     */
    public function allManufacturedPrefixes(): array
    {
        return array_keys(self::MANUFACTURED_PREFIX_BRANDS);
    }
}
