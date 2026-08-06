<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_sku_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->unique()->constrained('acumatica_inventory_items')->cascadeOnDelete();
            $table->string('ownership', 20)->nullable()->index();
            $table->string('business_line', 80)->nullable()->index();
            $table->string('site', 80)->nullable()->index();
            $table->string('machine', 120)->nullable()->index();
            $table->decimal('msi', 18, 4)->nullable();
            $table->decimal('safety_stock', 18, 4)->nullable();
            $table->decimal('buffer_stock', 18, 4)->nullable();
            $table->decimal('export_msi', 18, 4)->nullable();
            $table->decimal('export_requirement', 18, 4)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('inventory_warehouse_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('acumatica_inventory_items')->cascadeOnDelete();
            $table->string('inventory_id', 100)->index();
            $table->string('warehouse_id', 50)->index();
            $table->decimal('qty_on_hand', 15, 4)->nullable();
            $table->decimal('qty_available', 15, 4)->nullable();
            $table->string('uom', 20)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable()->index();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
            $table->unique(
                ['inventory_id', 'warehouse_id', 'sync_run_id'],
                'inventory_warehouse_snapshot_sync_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouse_balance_snapshots');
        Schema::dropIfExists('production_sku_plans');
    }
};
