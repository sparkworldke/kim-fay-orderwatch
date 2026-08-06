<?php

namespace Tests\Unit;

use App\Services\Operations\BackorderLineTransformer;
use App\Services\Operations\FillRateBusinessCategory;
use App\Services\Operations\OperationsCatalogResolver;
use PHPUnit\Framework\TestCase;

class BackorderLineTransformerTest extends TestCase
{
    private BackorderLineTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new BackorderLineTransformer(
            $this->createStub(OperationsCatalogResolver::class),
            new FillRateBusinessCategory(),
        );
    }

    public function test_aging_bucket_boundaries(): void
    {
        $this->assertSame('0-7', $this->transformer->agingBucket(0));
        $this->assertSame('0-7', $this->transformer->agingBucket(7));
        $this->assertSame('8-14', $this->transformer->agingBucket(8));
        $this->assertSame('8-14', $this->transformer->agingBucket(14));
        $this->assertSame('15-30', $this->transformer->agingBucket(15));
        $this->assertSame('15-30', $this->transformer->agingBucket(30));
        $this->assertSame('30+', $this->transformer->agingBucket(31));
        $this->assertNull($this->transformer->agingBucket(null));
    }

    public function test_effective_reason_prefers_stored_then_sales_order_fallback(): void
    {
        $this->assertSame('manual', $this->transformer->effectiveReason(' manual ', 'erp'));
        $this->assertSame('erp', $this->transformer->effectiveReason('unassigned', ' erp '));
        $this->assertNull($this->transformer->effectiveReason('', 'Unassigned'));
    }

    public function test_stock_reason_guidance_and_mismatch_rules(): void
    {
        $this->assertSame(
            'PRODUCTION',
            $this->transformer->suggestedReasonFamily('manufactured', 0, 'FGS', false),
        );
        $this->assertSame(
            'PROCUREMENT',
            $this->transformer->suggestedReasonFamily('trading', 0, 'FGS', false),
        );
        $this->assertSame(
            'LOGISTICS',
            $this->transformer->suggestedReasonFamily('manufactured', 100, 'FGS', true),
        );
        $this->assertTrue(
            $this->transformer->reasonStockMismatch(
                'out_of_stock_production',
                'manufactured',
                100,
                true,
            ),
        );
    }

    public function test_transform_keeps_residual_open_when_qty_on_shipments_equals_shipped(): void
    {
        $catalog = $this->createStub(OperationsCatalogResolver::class);
        $catalog->method('descriptionsForInventoryIds')->willReturn(collect());
        $catalog->method('classificationsForInventoryIds')->willReturn(collect());
        $catalog->method('stockForInventoryIds')->willReturn(collect());
        $catalog->method('stockForInventoryIdsByWarehouse')->willReturn(collect());
        $catalog->method('namesForCustomerIds')->willReturn(collect());
        $catalog->method('resolveProductName')->willReturn('Test');
        $catalog->method('classificationFieldsFor')->willReturn([]);
        $catalog->method('resolveCustomerName')->willReturn('Cust');
        $catalog->method('resolveUom')->willReturn('EA');

        $transformer = new BackorderLineTransformer($catalog, new FillRateBusinessCategory());

        $line = (object) [
            'inventory_id' => 'FAY001',
            'customer_acumatica_id' => 'C1',
            'customer_name' => null,
            'order_qty' => 6,
            'shipped_qty' => 3,
            'qty_on_shipments' => 3,
            'cancelled_qty' => 0,
            'open_qty' => 3,
            'backorder_qty' => 3,
            'unit_price' => 100,
            'revenue_at_risk' => 300,
            'warehouse_id' => 'FGS',
            'order_status' => 'Shipping',
            'shortfall_kind' => 'active_backorder',
            'reason_code' => null,
            'uom' => 'EA',
            'product_type' => 'manufactured',
            'first_backordered_at' => null,
            'first_backordered_at_is_backfilled' => false,
        ];

        $out = $transformer->transform(collect([$line]))->first();

        $this->assertSame(3.0, $out->open_qty);
        $this->assertSame(3.0, $out->backorder_qty);
        $this->assertSame(300.0, $out->revenue_at_risk);
    }
}
