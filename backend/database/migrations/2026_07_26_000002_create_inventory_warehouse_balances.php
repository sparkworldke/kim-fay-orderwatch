<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_warehouse_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('acumatica_inventory_items')->cascadeOnDelete();
            $table->string('inventory_id', 100);
            $table->string('warehouse_id', 50);
            $table->decimal('qty_on_hand', 15, 4)->default(0);
            $table->decimal('qty_available', 15, 4)->nullable();
            $table->string('uom', 20)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['inventory_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'qty_on_hand']);
            $table->index(['warehouse_id', 'synced_at']);
        });

        DB::table('acumatica_inventory_items')
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                $now = now();
                $rows = [];
                foreach ($items as $item) {
                    $warehouse = strtoupper(trim((string) ($item->default_warehouse_id ?: 'FGS')));
                    if ($warehouse !== 'FGS') {
                        continue;
                    }
                    $rows[] = [
                        'inventory_item_id' => $item->id,
                        'inventory_id' => $item->inventory_id,
                        'warehouse_id' => 'FGS',
                        'qty_on_hand' => $item->qty_on_hand ?? 0,
                        'qty_available' => $item->qty_available,
                        'uom' => $item->default_uom,
                        'sync_run_id' => $item->sync_run_id,
                        'synced_at' => $item->synced_at,
                        'raw_payload' => $item->raw_payload,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('inventory_warehouse_balances')->upsert(
                        $rows,
                        ['inventory_id', 'warehouse_id'],
                        ['inventory_item_id', 'qty_on_hand', 'qty_available', 'uom', 'sync_run_id', 'synced_at', 'raw_payload', 'updated_at'],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouse_balances');
    }
};
