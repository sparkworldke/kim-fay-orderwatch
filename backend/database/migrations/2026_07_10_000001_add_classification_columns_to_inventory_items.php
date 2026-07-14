<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds BI classification columns from the Stock Items BI export to the
 * acumatica_inventory_items table.
 *
 * New columns:
 *   - posting_class         (e.g. "DISPENSER", "BATTERIES")
 *   - item_group            (e.g. "FG-Dispensers-Fay")
 *   - sub_item_group        (e.g. "Dispensers")
 *   - trading_group         (e.g. "Kimfay Brand", "Partners")
 *   - sub_trading_group     (e.g. "Trading", "Manufactured")
 *   - conversion_factor     (numeric, e.g. 1, 6, 8)
 *   - profit_margin_target  (string, e.g. "10%", "40%")
 *   - supplier              (e.g. "Kim-Fay", "Duracell", "Danone")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->string('posting_class', 100)->nullable()->after('item_class');
            $table->string('item_group', 150)->nullable()->after('brand');
            $table->string('sub_item_group', 100)->nullable()->after('item_group');
            $table->string('trading_group', 100)->nullable()->after('sub_item_group');
            $table->string('sub_trading_group', 100)->nullable()->after('trading_group');
            $table->decimal('conversion_factor', 10, 4)->nullable()->after('sub_trading_group');
            $table->string('profit_margin_target', 20)->nullable()->after('conversion_factor');
            $table->string('supplier', 150)->nullable()->after('profit_margin_target');

            $table->index('posting_class');
            $table->index('supplier');
            $table->index('trading_group');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->dropIndex(['posting_class']);
            $table->dropIndex(['supplier']);
            $table->dropIndex(['trading_group']);
            $table->dropColumn([
                'posting_class',
                'item_group',
                'sub_item_group',
                'trading_group',
                'sub_trading_group',
                'conversion_factor',
                'profit_margin_target',
                'supplier',
            ]);
        });
    }
};
