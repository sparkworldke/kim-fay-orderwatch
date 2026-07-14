<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_sku_insights')) {
            return;
        }

        if (! Schema::hasColumn('inventory_sku_insights', 'created_at')) {
            Schema::table('inventory_sku_insights', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasColumn('inventory_sku_insights', 'updated_at')) {
            Schema::table('inventory_sku_insights', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_sku_insights') && Schema::hasColumn('inventory_sku_insights', 'updated_at')) {
            Schema::table('inventory_sku_insights', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }
};