<?php

namespace Tests\Feature;

use App\Models\AcumaticaInventoryItem;
use App\Models\Product;
use App\Services\Imports\ProductCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_positional_import_maps_duplicate_description_columns_and_skips_unmatched(): void
    {
        AcumaticaInventoryItem::create([
            'inventory_id' => 'COSTP0030',
            'description' => 'Synced product',
            'is_stock_item' => true,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'products').'.csv';
        file_put_contents($path,
            "Inventory ID,Description,Item Class,Posting Class,Brand,Description,Item Group,Sub Item Group,Trading Group,Sub Trading Group,Conversion Factor,UOM,Profit Margin Target,Supplier\n".
            "COSTP0030,Cosy Poa TP Emb. unwrapped. 4x10s White 150 Sheets,FINGOODS -TISSUE,TISSUE,Cosy Poa,FG-Tissue-Toilet Tissue-Cosy Poa,Toilet Tissue,40s,Kimfay Brand,Manufactured,0.75,Bales of 40s,25%,Kim-Fay\n".
            "UNKNOWN,Unknown Product,CLASS,POST,Brand,Path,Group,Sub,Portfolio,Trading,1,Each,10%,Supplier\n"
        );

        $result = app(ProductCsvImportService::class)->import($path);
        $product = Product::with(['brand', 'category', 'subCategory', 'tradingGroup'])
            ->where('inventory_id', 'COSTP0030')->firstOrFail();

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(1, $result['unmatched_count']);
        $this->assertSame('Cosy Poa', $product->brand?->name);
        $this->assertSame('Toilet Tissue', $product->category?->name);
        $this->assertSame('40s', $product->subCategory?->name);
        // CSV: Trading Group → portfolio_group; Sub Trading Group → trading_groups taxonomy.
        $this->assertSame('Manufactured', $product->tradingGroup?->name);
        $this->assertSame('Kimfay Brand', $product->portfolio_group);
        $this->assertSame('0.7500', $product->conversion_factor);
        $this->assertSame('Bales of 40s', $product->uom);
        $this->assertSame('0.2500', $product->profit_margin_target);
        // Ownership is brand-classified (Kimfay/Cosy Poa = manufactured), not from Trading Group text.
        $this->assertSame('manufactured', $product->ownership);
        $this->assertSame('manufactured', $product->brand?->ownership);
        $inventory = AcumaticaInventoryItem::where('inventory_id', 'COSTP0030')->firstOrFail();
        $this->assertSame('manufactured', $inventory->product_type);
        $this->assertSame('Cosy Poa', $inventory->brand);
        $this->assertSame('Toilet Tissue', $inventory->item_group);
        $this->assertSame('Manufactured', $inventory->trading_group);
    }

    public function test_locked_product_is_not_changed_by_later_import(): void
    {
        $inventory = AcumaticaInventoryItem::create(['inventory_id' => 'SKU1', 'description' => 'SKU', 'is_stock_item' => true]);
        Product::create([
            'inventory_id' => 'SKU1', 'acumatica_inventory_item_id' => $inventory->id,
            'name' => 'Manual Name', 'import_locked' => true, 'source' => 'manual',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'products').'.csv';
        file_put_contents($path,
            "Inventory ID,Description,Item Class,Posting Class,Brand,Description,Item Group,Sub Item Group,Trading Group,Sub Trading Group,Conversion Factor,UOM,Profit Margin Target,Supplier\n".
            "SKU1,Imported Name,CLASS,POST,Brand,Path,Group,Sub,Portfolio,Trading,1,Each,10%,Supplier\n"
        );

        $result = app(ProductCsvImportService::class)->import($path);

        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame('Manual Name', Product::where('inventory_id', 'SKU1')->value('name'));
    }
}
