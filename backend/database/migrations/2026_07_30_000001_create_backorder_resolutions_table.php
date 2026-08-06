<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backorder_resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('order_nbr', 50)->index();
            $table->string('inventory_id', 100)->index();
            $table->string('customer_acumatica_id', 50)->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('warehouse_id', 50)->nullable();
            $table->string('uom', 20)->nullable();
            $table->string('currency_id', 10)->nullable();
            $table->string('reason_code', 80)->nullable()->index();
            $table->text('reason_notes')->nullable();
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('revenue_at_risk', 15, 2)->default(0);
            $table->decimal('order_qty', 15, 4)->default(0);
            $table->decimal('last_open_qty', 15, 4)->default(0);
            $table->decimal('last_backorder_qty', 15, 4)->default(0);
            // Best-known status as of the last sync before the line dropped out of the
            // active set — not necessarily the exact status at the moment it resolved,
            // since a fully-completed order simply stops appearing in the open-orders fetch.
            $table->string('last_fulfillment_status', 60)->nullable();
            $table->timestamp('first_backordered_at')->nullable()->index();
            $table->boolean('first_backordered_at_is_backfilled')->default(false);
            $table->timestamp('resolved_at')->index();
            $table->unsignedInteger('days_to_resolve')->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamps();

            // One order/item pair can go backordered and resolve more than once over time
            // (recurring shortage) — deliberately no unique constraint here.
            $table->index(['order_nbr', 'inventory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backorder_resolutions');
    }
};
