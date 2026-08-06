<?php

namespace Tests\Unit;

use App\Models\AcumaticaInventoryItem;
use Database\Seeders\InventoryBrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryBrandSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_updates_brand_and_product_type_from_csv_map_logic(): void
    {
        // Minimal fixture matching seeder map shape (unit-test the helpers via run with temp file).
        $csv = sys_get_temp_dir().DIRECTORY_SEPARATOR.'products-with-brands-test.csv';
        file_put_contents($csv, implode("\n", [
            'CODE,NAME,BRAND',
            'APTML0004,Aptamil Infant 800g,Aptamil',
            'COWGT0001,Cow & Gate First 400g,Cow & Gate',
            'FAYTP0008,Fay TP Emb. Unwrap. 4x10s White,Fay',
            'COSTP0024,Cosy Poa TP Emb. Wrap. 4x10s Pink,Cosy',
            'VATOL0010,Vatika Coconut Hair Oil 200Ml,Vatika',
            'BIOOL0001,Bio Oil 125ml,Bio Oil',
        ]));

        AcumaticaInventoryItem::create([
            'inventory_id' => 'APTML0004', 'description' => 'Aptamil Infant 800g',
            'brand' => null, 'product_type' => 'manufactured', 'qty_on_hand' => 0,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'COWGT0001', 'description' => 'Cow & Gate First 400g',
            'brand' => null, 'product_type' => 'manufactured', 'qty_on_hand' => 0,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'FAYTP0008', 'description' => 'Fay TP',
            'brand' => null, 'product_type' => 'trading', 'qty_on_hand' => 0,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'COSTP0024', 'description' => 'Cosy Poa TP Emb. Wrap. 4x10s Pink',
            'brand' => null, 'product_type' => 'trading', 'qty_on_hand' => 0,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'VATOL0010', 'description' => 'Vatika oil',
            'brand' => null, 'product_type' => 'manufactured', 'qty_on_hand' => 0,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'BIOOL0001', 'description' => 'Bio Oil',
            'brand' => null, 'product_type' => 'manufactured', 'qty_on_hand' => 0,
        ]);

        // Do not overwrite the real seed CSV — pass a temp path via env.
        putenv('INVENTORY_BRAND_CSV_PATH='.$csv);
        $_ENV['INVENTORY_BRAND_CSV_PATH'] = $csv;
        $_SERVER['INVENTORY_BRAND_CSV_PATH'] = $csv;

        $this->seed(InventoryBrandSeeder::class);

        putenv('INVENTORY_BRAND_CSV_PATH');
        unset($_ENV['INVENTORY_BRAND_CSV_PATH'], $_SERVER['INVENTORY_BRAND_CSV_PATH']);

        $this->assertSame('Aptamil', AcumaticaInventoryItem::where('inventory_id', 'APTML0004')->value('brand'));
        $this->assertSame('trading', AcumaticaInventoryItem::where('inventory_id', 'APTML0004')->value('product_type'));
        $this->assertSame('Cow & Gate', AcumaticaInventoryItem::where('inventory_id', 'COWGT0001')->value('brand'));
        $this->assertSame('Fay', AcumaticaInventoryItem::where('inventory_id', 'FAYTP0008')->value('brand'));
        $this->assertSame('manufactured', AcumaticaInventoryItem::where('inventory_id', 'FAYTP0008')->value('product_type'));
        $this->assertSame('Cosy Poa', AcumaticaInventoryItem::where('inventory_id', 'COSTP0024')->value('brand'));
        $this->assertSame('Vatika', AcumaticaInventoryItem::where('inventory_id', 'VATOL0010')->value('brand'));
        $this->assertSame('Bio Oil', AcumaticaInventoryItem::where('inventory_id', 'BIOOL0001')->value('brand'));
        $this->assertSame('trading', AcumaticaInventoryItem::where('inventory_id', 'BIOOL0001')->value('product_type'));

        @unlink($csv);
    }
}
