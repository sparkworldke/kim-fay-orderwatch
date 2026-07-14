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

    /** @return array<int, array<string, mixed>> */
    public function hierarchyOptions(): array
    {
        $manufacturedBrands = AcumaticaInventoryItem::query()
            ->where('product_type', 'manufactured')
            ->whereNotNull('brand')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->filter()
            ->values();

        $tradingBrands = AcumaticaInventoryItem::query()
            ->where('product_type', 'trading')
            ->whereNotNull('brand')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->filter()
            ->values();

        $partnerBrands = $tradingBrands;

        return [
            [
                'key' => 'manufactured',
                'label' => 'Kimfay Brands',
                'brands' => $manufacturedBrands->map(fn ($brand) => [
                    'brand' => $brand,
                    'categories' => $this->categoriesForBrand($brand),
                ])->values()->all(),
            ],
            [
                'key' => 'trading',
                'label' => 'Partner Brands',
                'brands' => $partnerBrands->map(fn ($brand) => [
                    'brand' => $brand,
                    'categories' => $this->categoriesForBrand($brand),
                ])->values()->all(),
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
        $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';

        if ($partnerBrand === 'manufactured') {
            $query->where($prefix . 'product_type', 'manufactured');
        } elseif ($partnerBrand === 'trading') {
            $query->where($prefix . 'product_type', 'trading');
        }

        if ($brand !== null && $brand !== '') {
            $query->where($prefix . 'brand', $brand);
        }

        if ($category !== null && $category !== '') {
            $query->where(function (Builder $cat) use ($prefix, $category) {
                $cat->where($prefix . 'posting_class', $category)
                    ->orWhere($prefix . 'item_class', $category);
            });
        }

        return $query;
    }

    /** @return list<string>|null Null when no brand filter is active. */
    public function inventoryIdsMatching(?string $partnerBrand, ?string $brand, ?string $category): ?array
    {
        if (($partnerBrand === null || $partnerBrand === '')
            && ($brand === null || $brand === '')
            && ($category === null || $category === '')) {
            return null;
        }

        $query = AcumaticaInventoryItem::query()->select('inventory_id');
        $this->applyInventoryScope($query, $partnerBrand, $brand, $category);

        return $query->pluck('inventory_id')->all();
    }

    /** @return list<string> */
    private function categoriesForBrand(string $brand): array
    {
        return AcumaticaInventoryItem::query()
            ->where('brand', $brand)
            ->selectRaw('COALESCE(NULLIF(posting_class, ""), NULLIF(item_class, "")) as category')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values()
            ->all();
    }
}