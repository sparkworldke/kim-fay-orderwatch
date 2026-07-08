<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->string('item_status', 50)->nullable()->after('item_class');
            $table->decimal('last_cost', 10, 4)->nullable()->after('sales_price');
            $table->decimal('average_cost', 10, 4)->nullable()->after('last_cost');
            $table->timestamp('last_modified_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['item_status', 'last_cost', 'average_cost', 'last_modified_at']);
        });
    }
};
