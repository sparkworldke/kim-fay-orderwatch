<?php

namespace Tests\Unit;

use App\Models\AcumaticaInventoryItem;
use App\Services\Admin\ProductBrandClassifier;
use App\Services\Team\BrandFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    private BrandFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BrandFilterService(new ProductBrandClassifier());
    }

    public function test_kimfay_hierarchy_only_lists_the_seven_allowlisted_brands(): void
    {
        AcumaticaInventoryItem::create([
            'inventory_id' => 'FAYTP001',
            'description' => 'Fay Toilet Paper',
            'brand' => 'Fay',
            'product_type' => 'manufactured',
            'posting_class' => 'Toilet Paper',
            'sub_item_group' => 'Toilet Paper',
            'qty_on_hand' => 10,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SIFWP001',
            'description' => 'Sifa Wipes',
            'brand' => null,
            'product_type' => 'manufactured',
            'posting_class' => 'Wipes',
            'sub_item_group' => 'Wipes',
            'qty_on_hand' => 5,
        ]);
        // Extra manufactured label that must NOT appear as its own brand option.
        AcumaticaInventoryItem::create([
            'inventory_id' => 'STD001',
            'description' => 'Standard Roll',
            'brand' => 'Standard',
            'product_type' => 'manufactured',
            'qty_on_hand' => 1,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'DOV001',
            'description' => 'Dove Soap',
            'brand' => 'Dove',
            'product_type' => 'trading',
            'posting_class' => 'Soap',
            'qty_on_hand' => 3,
        ]);
        // Partner brands often missing from master brand column — must still list + match via prefix.
        AcumaticaInventoryItem::create([
            'inventory_id' => 'APTML001',
            'description' => 'Aptamil Stage 1',
            'brand' => null,
            'product_type' => 'manufactured', // mis-tagged; prefix APTML wins
            'posting_class' => 'Formula',
            'qty_on_hand' => 2,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'COWGT001',
            'description' => 'Cow and Gate Growing Up',
            'brand' => 'Cow and Gate',
            'product_type' => 'trading',
            'posting_class' => 'Formula',
            'qty_on_hand' => 2,
        ]);

        $hierarchy = $this->service->hierarchyOptions();

        $this->assertSame('manufactured', $hierarchy[0]['key']);
        $this->assertSame('Kimfay Brands', $hierarchy[0]['label']);

        $mfgNames = array_column($hierarchy[0]['brands'], 'brand');
        $this->assertSame([
            'Kimfay',
            'Fay',
            'Sifa',
            'Kleenex',
            'Cosy',
            'Cosy Poa',
            'Tishu Poa',
            'Ultra Clean',
        ], $mfgNames);
        $this->assertNotContains('Standard', $mfgNames);

        $mfgBrands = collect($hierarchy[0]['brands'])->keyBy('brand');
        $this->assertContains('Toilet Paper', $mfgBrands['Fay']['categories']);
        $this->assertContains('Wipes', $mfgBrands['Sifa']['categories']);

        $tradingNames = array_column($hierarchy[1]['brands'], 'brand');
        $this->assertContains('Airoma', $tradingNames);
        $this->assertContains('Aptamil', $tradingNames);
        $this->assertContains('Cow & Gate', $tradingNames);
        $this->assertContains('Dove', $tradingNames);
        $this->assertContains('Vatika', $tradingNames);
        // Official partners always appear (even with no inventory for that brand).
        $this->assertSame('Airoma', $tradingNames[0]);

        $tradingBrands = collect($hierarchy[1]['brands'])->keyBy('brand');
        $this->assertContains('Formula', $tradingBrands['Aptamil']['categories']);
        $this->assertContains('Formula', $tradingBrands['Cow & Gate']['categories']);
        $this->assertFalse($tradingBrands->has('Fay'));
    }

    public function test_inventory_ids_matching_respects_partner_brand_and_brand(): void
    {
        AcumaticaInventoryItem::create([
            'inventory_id' => 'FAY001',
            'brand' => 'Fay',
            'product_type' => 'manufactured',
            'posting_class' => 'Toilet Paper',
            'qty_on_hand' => 1,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SIF001',
            'brand' => 'Sifa',
            'product_type' => 'manufactured',
            'posting_class' => 'Wipes',
            'qty_on_hand' => 1,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'DOV001',
            'brand' => 'Dove',
            'product_type' => 'trading',
            'qty_on_hand' => 1,
        ]);

        $mfg = $this->service->inventoryIdsMatching('manufactured', null, null);
        $this->assertEqualsCanonicalizing(['FAY001', 'SIF001'], $mfg);

        $fay = $this->service->inventoryIdsMatching('manufactured', 'Fay', null);
        $this->assertSame(['FAY001'], $fay);

        $wipes = $this->service->inventoryIdsMatching('manufactured', null, 'Wipes');
        $this->assertSame(['SIF001'], $wipes);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'APTML999',
            'brand' => null,
            'product_type' => 'trading',
            'qty_on_hand' => 1,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'COWGT999',
            'brand' => 'Cow&Gate',
            'product_type' => 'trading',
            'qty_on_hand' => 1,
        ]);

        $aptamil = $this->service->inventoryIdsMatching('trading', 'Aptamil', null);
        $this->assertSame(['APTML999'], $aptamil);

        $cow = $this->service->inventoryIdsMatching('trading', 'Cow & Gate', null);
        $this->assertSame(['COWGT999'], $cow);

        $this->assertNull($this->service->inventoryIdsMatching(null, null, null));
    }

    public function test_resolve_filter_uses_prefixes_when_inventory_master_is_empty(): void
    {
        // No inventory rows — still resolve APTML / COWGT prefixes so backorder lines match.
        $apt = $this->service->resolveInventoryFilter('trading', 'Aptamil', null);
        $this->assertNotNull($apt);
        $this->assertSame([], $apt['inventory_ids']);
        $this->assertContains('APTML', $apt['prefixes']);
        $this->assertContains('APT', $apt['prefixes']);

        $cow = $this->service->resolveInventoryFilter(null, 'Cow & Gate', null);
        $this->assertNotNull($cow);
        $this->assertContains('COWGT', $cow['prefixes']);
        $this->assertContains('COW', $cow['prefixes']);
    }
}
