<?php

use App\Models\AcumaticaInventoryItem;
use App\Services\Admin\ProductBrandClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('item_class');
            $table->string('product_type', 20)->default('manufactured')->after('brand');
            $table->index('brand');
            $table->index('product_type');
        });

        $classifier = new ProductBrandClassifier();

        AcumaticaInventoryItem::query()
            ->select('id', 'description', 'inventory_id')
            ->chunkById(500, function ($items) use ($classifier) {
                foreach ($items as $item) {
                    $item->forceFill($classifier->classify($item->description, $item->inventory_id))->save();
                }
            });
    }

    public function down(): void
    {
        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->dropIndex(['brand']);
            $table->dropIndex(['product_type']);
            $table->dropColumn(['brand', 'product_type']);
        });
    }
};
