<?php

namespace Tests\Unit;

use App\Services\Admin\ProductBrandClassifier;
use PHPUnit\Framework\TestCase;

class ProductBrandClassifierTest extends TestCase
{
    private ProductBrandClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ProductBrandClassifier();
    }

    public function test_kimfay_allowlist_is_exactly_the_seven_brands(): void
    {
        $this->assertSame([
            'Kimfay',
            'Fay',
            'Sifa',
            'Kleenex',
            'Cosy',
            'Cosy Poa',
            'Tishu Poa',
            'Ultra Clean',
        ], $this->classifier->kimfayBrandAllowlist());
    }

    public function test_classifies_trading_brands_from_description(): void
    {
        $result = $this->classifier->classify('Dove Beauty Bar 100g', 'DOV001');

        $this->assertSame('Dove', $result['brand']);
        $this->assertSame('trading', $result['product_type']);
    }

    public function test_classifies_manufactured_brands_from_inventory_prefix(): void
    {
        $fay = $this->classifier->classify('Tissue roll', 'FAYFL0010');
        $this->assertSame('Fay', $fay['brand']);
        $this->assertSame('manufactured', $fay['product_type']);

        $sifa = $this->classifier->classify(null, 'SIFTP0015');
        $this->assertSame('Sifa', $sifa['brand']);

        $kleen = $this->classifier->classify(null, 'KLE001');
        $this->assertSame('Kleenex', $kleen['brand']);

        $ultra = $this->classifier->classify(null, 'ULT001');
        $this->assertSame('Ultra Clean', $ultra['brand']);
    }

    public function test_cosy_poa_wins_over_cosy_in_description(): void
    {
        $poa = $this->classifier->classify('Cosy Poa Soft Pack', 'COSTP0001');
        $this->assertSame('Cosy Poa', $poa['brand']);

        $cosy = $this->classifier->classify('Cosy toilet paper', 'COSTP0004');
        $this->assertSame('Cosy', $cosy['brand']);
    }

    public function test_trading_prefix_wins_over_manufactured_lookalikes(): void
    {
        $result = $this->classifier->classify(null, 'COWGT0001');

        $this->assertSame('Cow & Gate', $result['brand']);
        $this->assertSame('trading', $result['product_type']);
    }

    public function test_aptamil_and_cow_gate_use_acumatica_prefixes_aptml_and_cowgt(): void
    {
        // Canonical Acumatica inventory prefixes
        $this->assertSame('Aptamil', $this->classifier->resolveBrand(null, 'APTML0001', null));
        $this->assertSame('Cow & Gate', $this->classifier->resolveBrand(null, 'COWGT0001', null));
        $this->assertSame('trading', $this->classifier->productTypeForInventoryId('APTML0001'));
        $this->assertSame('trading', $this->classifier->productTypeForInventoryId('COWGT0001'));

        // Short fallbacks still work
        $this->assertSame('Aptamil', $this->classifier->resolveBrand(null, 'APT001', null));
        $this->assertSame('Cow & Gate', $this->classifier->resolveBrand('Cow and Gate', 'COW001', null));

        $this->assertSame('Cow & Gate', $this->classifier->normalizePartnerBrand('Cow&Gate'));
        $this->assertSame('Aptamil', $this->classifier->normalizePartnerBrand('aptamil'));
        $this->assertTrue($this->classifier->isTradingInventoryId('APTML123'));
        $this->assertTrue($this->classifier->isTradingInventoryId('COWGT0001'));
        $this->assertContains('APTML', $this->classifier->prefixesForBrand('Aptamil'));
        $this->assertContains('COWGT', $this->classifier->prefixesForBrand('Cow & Gate'));
        $this->assertContains('Aptamil', $this->classifier->partnerBrandAllowlist());
        $this->assertContains('Cow & Gate', $this->classifier->partnerBrandAllowlist());
    }

    public function test_vatika_and_hobby_prefixes_from_brands_md(): void
    {
        $this->assertSame('Vatika', $this->classifier->resolveBrand(null, 'VATOL0010', 'Vatika Coconut Hair Oil 200Ml'));
        $this->assertSame('Vatika', $this->classifier->resolveBrand(null, 'VATSH0031', null));
        $this->assertSame('Vatika', $this->classifier->resolveBrand(null, 'VATCN0019', null));
        $this->assertSame('Vatika', $this->classifier->resolveBrand(null, 'VATCR0012', null));
        // Prefix wins over free-gift "Vatika" text in Hobby description.
        $this->assertSame(
            'Hobby',
            $this->classifier->resolveBrand(
                null,
                'HOBBW0059',
                'Hobby Body Wash Pomegranate Blossom 1000Ml + Free 200ml Vatika Shampoo',
            ),
        );
        $this->assertContains('VATOL', $this->classifier->prefixesForBrand('Vatika'));
        $this->assertContains('HOBBW', $this->classifier->prefixesForBrand('Hobby'));
    }

    public function test_normalize_kimfay_brand_aliases(): void
    {
        $this->assertSame('Kimfay', $this->classifier->normalizeKimfayBrand('Kim-Fay'));
        $this->assertSame('Kimfay', $this->classifier->normalizeKimfayBrand('Kimfay'));
        $this->assertSame('Fay', $this->classifier->normalizeKimfayBrand('Fay Tissues'));
        $this->assertSame('Kleenex', $this->classifier->normalizeKimfayBrand('Kleneex'));
        $this->assertSame('Ultra Clean', $this->classifier->normalizeKimfayBrand('UltraClean'));
        $this->assertSame('Cosy Poa', $this->classifier->normalizeKimfayBrand('Cosy Poa'));
        $this->assertNull($this->classifier->normalizeKimfayBrand('Dove'));
    }

    public function test_resolve_brand_returns_canonical_kimfay_name(): void
    {
        $resolved = $this->classifier->resolveBrand('Fay Tissues', 'FAY001', 'Something');
        $this->assertSame('Fay', $resolved);

        $fromPrefix = $this->classifier->resolveBrand(null, 'SIFTP0015', null);
        $this->assertSame('Sifa', $fromPrefix);
    }

    public function test_prefixes_for_brand(): void
    {
        $this->assertSame(['FAY'], $this->classifier->prefixesForBrand('Fay'));
        $this->assertSame(['SIF'], $this->classifier->prefixesForBrand('sifa'));
        $this->assertContains('KLE', $this->classifier->prefixesForBrand('Kleenex'));
        $this->assertSame([], $this->classifier->prefixesForBrand('Unknown Brand'));
    }
}
