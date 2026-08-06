<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderBusinessFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_segment_parent_and_outlet_filters_are_dynamic(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrator']));

        AcumaticaCustomer::create([
            'acumatica_id' => 'KP-PARENT',
            'name' => 'KP Parent',
            'customer_class' => 'KP01',
            'status' => 'Active',
            'is_main_account' => true,
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'KP-BRANCH',
            'parent_acumatica_id' => 'KP-PARENT',
            'name' => 'KP Branch',
            'customer_class' => 'KP01',
            'status' => 'Active',
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CS-STANDALONE',
            'name' => 'Consumer Standalone',
            'customer_class' => 'CS01',
            'status' => 'Active',
        ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'FAY-1', 'description' => 'Fay Product',
            'brand' => 'Fay', 'is_stock_item' => true,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'COSY-1', 'description' => 'Cosy Product',
            'brand' => 'Cosy', 'is_stock_item' => true,
        ]);

        $kpOrder = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-KP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'KP-BRANCH',
            'customer_name' => 'KP Branch',
            'status' => 'Open',
            'order_date' => now(),
        ]);
        $csOrder = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-CS',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CS-STANDALONE',
            'customer_name' => 'Consumer Standalone',
            'status' => 'Open',
            'order_date' => now(),
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $kpOrder->id, 'line_nbr' => 1,
            'inventory_id' => 'FAY-1', 'order_qty' => 10,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $csOrder->id, 'line_nbr' => 1,
            'inventory_id' => 'COSY-1', 'order_qty' => 5,
        ]);

        $this->getJson('/api/orders/filter-options')
            ->assertOk()
            ->assertJsonFragment(['id' => 'KP-PARENT', 'name' => 'KP Parent'])
            ->assertJsonFragment(['id' => 'KP-BRANCH', 'name' => 'KP Branch'])
            ->assertJsonFragment(['id' => 'CS-STANDALONE', 'name' => 'Consumer Standalone']);

        $this->getJson('/api/orders?segments[]=KP&brands[]=Fay')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acumatica_order_nbr', 'SO-KP');

        $this->getJson('/api/orders?parent_customer_ids[]=KP-PARENT')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_acumatica_id', 'KP-BRANCH');

        $this->getJson('/api/orders?customer_ids[]=CS-STANDALONE')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acumatica_order_nbr', 'SO-CS');
    }
}
