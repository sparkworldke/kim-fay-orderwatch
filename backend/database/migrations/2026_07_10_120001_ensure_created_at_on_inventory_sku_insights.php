<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production inventory_sku_insights may lack Laravel timestamps (created_at / updated_at).
 * Eloquent updateOrCreate requires them when $timestamps is true (default).
 */
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

            // Backfill from generated_at when present so rows are not all-null.
            if (Schema::hasColumn('inventory_sku_insights', 'generated_at')) {
                DB::table('inventory_sku_insights')
                    ->whereNull('created_at')
                    ->update(['created_at' => DB::raw('COALESCE(generated_at, NOW())')]);
            }
        }

        if (! Schema::hasColumn('inventory_sku_insights', 'updated_at')) {
            Schema::table('inventory_sku_insights', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });

            if (Schema::hasColumn('inventory_sku_insights', 'generated_at')) {
                DB::table('inventory_sku_insights')
                    ->whereNull('updated_at')
                    ->update(['updated_at' => DB::raw('COALESCE(generated_at, NOW())')]);
            }
        }
    }

    public function down(): void
    {
        // Do not drop columns on rollback — they are required for Eloquent.
    }
};
