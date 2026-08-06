<?php

namespace App\Services\Team;

use App\Models\AcumaticaInventoryItem;
use App\Services\Admin\ProductBrandClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BrandFilterService
{
    public function __construct(
        private readonly ProductBrandClassifier $brandClassifier,
    ) {}

    /**
     * Cascade options for Brand group → Brand → Category filters.
     *
     * Brands under "Kimfay Brands" are fixed: Kimfay, Fay, Sifa, Kleneex, Cosy, Cosy Poa, Tishu Poa, Ultra Clean.
     * Categories are dynamic per brand (sub item group / posting class / item class),
     * e.g. Toilet Paper, Wipes.
     *
     * @return array<int, array{key: string, label: string, brands: list<array{brand: string, categories: list<string>}>}>
     */
    public function hierarchyOptions(): array
    {
        $items = AcumaticaInventoryItem::query()
            ->get([
                'inventory_id',
                'description',
                'brand',
                'product_type',
                'posting_class',
                'item_class',
                'sub_item_group',
            ]);

        $manufacturedItems = $items->filter(fn (AcumaticaInventoryItem $item) => $this->isManufactured($item));
        $tradingItems = $items->filter(fn (AcumaticaInventoryItem $item) => ! $this->isManufactured($item));

        return [
            [
                'key' => 'manufactured',
                'label' => 'Kimfay Brands',
                // Fixed allowlist: Kimfay, Fay, Sifa, Kleneex, Cosy, Cosy Poa, Tishu Poa, Ultra Clean.
                'brands' => $this->groupKimfayBrandsAndCategories($manufacturedItems),
            ],
            [
                'key' => 'trading',
                'label' => 'Partner Brands',
                // Always include Aptamil, Cow & Gate, Dove, … plus any extra trading brands from data.
                'brands' => $this->groupPartnerBrandsAndCategories($tradingItems),
            ],
        ];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyInventoryScope(
        Builder $query,
        ?string $partnerBrand,
        ?string $brand,
        ?string $category,
        string $tableAlias = '',
    ): Builder {
        $prefix = $tableAlias !== '' ? $tableAlias.'.' : '';

        if ($partnerBrand === 'manufactured') {
            // Prefer explicit product_type; null/empty left in so prefix-based master rows still match.
            $query->where(function (Builder $q) use ($prefix) {
                $q->where($prefix.'product_type', 'manufactured')
                    ->orWhereNull($prefix.'product_type')
                    ->orWhere($prefix.'product_type', '');
            });
        } elseif ($partnerBrand === 'trading') {
            $query->where($prefix.'product_type', 'trading');
        }

        if ($brand !== null && $brand !== '') {
            $this->applyBrandMatch($query, $brand, $prefix);
        }

        if ($category !== null && $category !== '') {
            $this->applyCategoryMatch($query, $category, $prefix);
        }

        return $query;
    }

    /** @return list<string>|null Null when no brand filter is active. */
    public function inventoryIdsMatching(?string $partnerBrand, ?string $brand, ?string $category): ?array
    {
        $filter = $this->resolveInventoryFilter($partnerBrand, $brand, $category);
        if ($filter === null) {
            return null;
        }

        // Prefer explicit inventory IDs when the master is populated.
        // When only prefixes are available (empty inventory master), return [] so
        // callers that only support whereIn still get a safe empty set — prefer
        // applyToInventoryIdColumn() for backorders/fill-rate so prefix SQL works.
        return $filter['inventory_ids'];
    }

    /**
     * Resolve brand/group/category into inventory IDs and/or inventory-ID prefixes.
     * Prefixes let Aptamil (APT…) and Cow & Gate (COW…) match even when
     * acumatica_inventory_items is empty or missing brand columns.
     *
     * @return array{inventory_ids: list<string>, prefixes: list<string>}|null
     */
    public function resolveInventoryFilter(?string $partnerBrand, ?string $brand, ?string $category): ?array
    {
        if (($partnerBrand === null || $partnerBrand === '')
            && ($brand === null || $brand === '')
            && ($category === null || $category === '')) {
            return null;
        }

        $prefixes = $this->prefixesForFilter($partnerBrand, $brand);

        // Category needs master classification — without inventory rows we can only use prefixes.
        $items = AcumaticaInventoryItem::query()
            ->get([
                'inventory_id',
                'description',
                'brand',
                'product_type',
                'posting_class',
                'item_class',
                'sub_item_group',
            ])
            ->filter(function (AcumaticaInventoryItem $item) use ($partnerBrand, $brand, $category) {
                if ($partnerBrand === 'manufactured' && ! $this->isManufactured($item)) {
                    return false;
                }
                if ($partnerBrand === 'trading' && $this->isManufactured($item)) {
                    return false;
                }

                if ($brand !== null && $brand !== '') {
                    $resolved = $this->resolvedBrandForItem($item);
                    $want = $this->brandClassifier->normalizePartnerBrand($brand)
                        ?? $this->brandClassifier->normalizeKimfayBrand($brand)
                        ?? $brand;
                    $have = $this->brandClassifier->normalizePartnerBrand($resolved)
                        ?? $this->brandClassifier->normalizeKimfayBrand($resolved)
                        ?? $resolved;
                    if ($have === null || strcasecmp((string) $have, (string) $want) !== 0) {
                        return false;
                    }
                }

                if ($category !== null && $category !== '') {
                    if (! $this->itemMatchesCategory($item, $category)) {
                        return false;
                    }
                }

                return true;
            });

        $ids = $items->pluck('inventory_id')->filter()->values()->all();

        // When a category is set and master has no matches, do not fall back to broad
        // brand prefixes (that would ignore the category). Empty IDs + no usable prefix path.
        if ($category !== null && $category !== '' && $ids === []) {
            return ['inventory_ids' => [], 'prefixes' => []];
        }

        // Drop prefixes that already have no brand context when category-only… not applicable.

        return [
            'inventory_ids' => $ids,
            'prefixes' => $prefixes,
        ];
    }

    /**
     * Apply brand/group/category filter to any query with an inventory_id column.
     * Uses inventory master IDs when present, and inventory-ID prefixes as fallback
     * (critical when inventory sync has not run but backorders already have APT% / COW% rows).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function applyToInventoryIdColumn(
        Builder $query,
        string $inventoryColumn,
        ?string $partnerBrand,
        ?string $brand,
        ?string $category,
    ): void {
        $filter = $this->resolveInventoryFilter($partnerBrand, $brand, $category);
        if ($filter === null) {
            return;
        }

        $ids = $filter['inventory_ids'];
        $prefixes = $filter['prefixes'];

        if ($ids === [] && $prefixes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($inventoryColumn, $ids, $prefixes) {
            if ($ids !== []) {
                $q->whereIn($inventoryColumn, $ids);
            }
            foreach ($prefixes as $prefix) {
                $q->orWhere($inventoryColumn, 'like', $prefix.'%');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function prefixesForFilter(?string $partnerBrand, ?string $brand): array
    {
        if ($brand !== null && $brand !== '') {
            return $this->brandClassifier->prefixesForBrand($brand);
        }

        if ($partnerBrand === 'trading') {
            return $this->brandClassifier->allTradingPrefixes();
        }

        if ($partnerBrand === 'manufactured') {
            return $this->brandClassifier->allManufacturedPrefixes();
        }

        return [];
    }

    /**
     * Kimfay brand dropdown: only the official seven brands, in fixed order.
     * Categories are still filled dynamically from inventory under each brand.
     *
     * @param  Collection<int, AcumaticaInventoryItem>  $items
     * @return list<array{brand: string, categories: list<string>}>
     */
    private function groupKimfayBrandsAndCategories(Collection $items): array
    {
        $allowlist = $this->brandClassifier->kimfayBrandAllowlist();
        /** @var array<string, array<string, true>> $byBrand */
        $byBrand = [];
        foreach ($allowlist as $brand) {
            $byBrand[$brand] = [];
        }

        foreach ($items as $item) {
            $brand = $this->resolvedBrandForItem($item);
            $canonical = $this->brandClassifier->normalizeKimfayBrand($brand);
            if ($canonical === null || ! isset($byBrand[$canonical])) {
                continue;
            }

            foreach ($this->categoriesForItem($item) as $category) {
                $byBrand[$canonical][$category] = true;
            }
        }

        $result = [];
        foreach ($allowlist as $brandName) {
            $cats = array_keys($byBrand[$brandName] ?? []);
            natcasesort($cats);
            $result[] = [
                'brand' => $brandName,
                'categories' => array_values($cats),
            ];
        }

        return $result;
    }

    /**
     * Partner brand dropdown: always list official partners (Aptamil, Cow & Gate, …)
     * then any extra trading brands present in inventory (sorted).
     *
     * @param  Collection<int, AcumaticaInventoryItem>  $items
     * @return list<array{brand: string, categories: list<string>}>
     */
    private function groupPartnerBrandsAndCategories(Collection $items): array
    {
        $allowlist = $this->brandClassifier->partnerBrandAllowlist();
        /** @var array<string, array<string, true>> $byBrand */
        $byBrand = [];
        foreach ($allowlist as $brand) {
            $byBrand[$brand] = [];
        }

        foreach ($items as $item) {
            $brand = $this->resolvedBrandForItem($item);
            if ($brand === null || $brand === '') {
                continue;
            }
            if ($this->brandClassifier->isKimfayBrand($brand)) {
                continue;
            }

            $canonical = $this->brandClassifier->normalizePartnerBrand($brand) ?? $brand;
            if (! isset($byBrand[$canonical])) {
                $byBrand[$canonical] = [];
            }

            foreach ($this->categoriesForItem($item) as $category) {
                $byBrand[$canonical][$category] = true;
            }
        }

        $result = [];
        // Fixed official partners first (so Aptamil & Cow & Gate always appear).
        foreach ($allowlist as $brandName) {
            $cats = array_keys($byBrand[$brandName] ?? []);
            natcasesort($cats);
            $result[] = [
                'brand' => $brandName,
                'categories' => array_values($cats),
            ];
            unset($byBrand[$brandName]);
        }

        // Any other trading brands from data, A–Z.
        ksort($byBrand, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($byBrand as $brandName => $categories) {
            $cats = array_keys($categories);
            natcasesort($cats);
            $result[] = [
                'brand' => $brandName,
                'categories' => array_values($cats),
            ];
        }

        return $result;
    }

    private function isManufactured(AcumaticaInventoryItem $item): bool
    {
        // Partner inventory prefixes (COW, APT, DOV, …) always count as trading,
        // even when product_type was mis-tagged as manufactured in the master.
        if ($this->brandClassifier->isTradingInventoryId($item->inventory_id)) {
            return false;
        }

        $type = strtolower(trim((string) ($item->product_type ?? '')));
        if ($type === 'manufactured') {
            return true;
        }
        if ($type === 'trading') {
            return false;
        }

        return $this->brandClassifier->productTypeForInventoryId($item->inventory_id) === 'manufactured';
    }

    private function resolvedBrandForItem(AcumaticaInventoryItem $item): ?string
    {
        return $this->brandClassifier->resolveBrand(
            $item->brand,
            $item->inventory_id,
            $item->description,
        );
    }

    /** @return list<string> */
    private function categoriesForItem(AcumaticaInventoryItem $item): array
    {
        $out = [];
        foreach ([$item->sub_item_group, $item->posting_class, $item->item_class] as $value) {
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed !== '') {
                $out[$trimmed] = true;
            }
        }

        return array_keys($out);
    }

    private function itemMatchesCategory(AcumaticaInventoryItem $item, string $category): bool
    {
        $needle = strtolower(trim($category));
        foreach ($this->categoriesForItem($item) as $candidate) {
            if (strtolower($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyBrandMatch(Builder $query, string $brand, string $prefix): void
    {
        $canonical = $this->brandClassifier->normalizeKimfayBrand($brand) ?? trim($brand);
        $prefixes = $this->brandClassifier->prefixesForBrand($canonical);
        $needles = array_values(array_unique(array_filter([
            $brand,
            $canonical,
            strtolower($brand),
            strtolower($canonical),
        ])));

        $query->where(function (Builder $q) use ($prefix, $prefixes, $needles, $canonical) {
            foreach ($needles as $needle) {
                $q->orWhere($prefix.'brand', $needle)
                    ->orWhereRaw('LOWER('.$prefix.'brand) = ?', [strtolower($needle)])
                    ->orWhereRaw('LOWER('.$prefix.'brand) LIKE ?', [strtolower($needle).'%']);
            }

            // Master brand may say "Cosy Poa Soft" etc.
            if ($canonical !== '') {
                $q->orWhereRaw('LOWER('.$prefix.'brand) LIKE ?', ['%'.strtolower($canonical).'%']);
            }

            foreach ($prefixes as $invPrefix) {
                $q->orWhere(function (Builder $inner) use ($prefix, $invPrefix) {
                    $inner->where(function (Builder $emptyBrand) use ($prefix) {
                        $emptyBrand->whereNull($prefix.'brand')
                            ->orWhere($prefix.'brand', '');
                    })->where($prefix.'inventory_id', 'like', $invPrefix.'%');
                });
                // Also match prefix even when brand column has a non-canonical label.
                $q->orWhere($prefix.'inventory_id', 'like', $invPrefix.'%');
            }
        });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyCategoryMatch(Builder $query, string $category, string $prefix): void
    {
        $query->where(function (Builder $cat) use ($prefix, $category) {
            $cat->where($prefix.'posting_class', $category)
                ->orWhere($prefix.'item_class', $category)
                ->orWhere($prefix.'sub_item_group', $category);
        });
    }
}
