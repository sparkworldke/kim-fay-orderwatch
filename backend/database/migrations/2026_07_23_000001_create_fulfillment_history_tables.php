<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('fulfillment_history_snapshots',function(Blueprint $t){$t->id();$t->foreignId('sales_order_id')->nullable()->constrained('acumatica_sales_orders')->nullOnDelete();$t->string('order_nbr',50)->unique();$t->string('customer_acumatica_id',50)->nullable()->index();$t->date('order_date')->nullable()->index();$t->string('status',50);$t->timestamp('observed_at');$t->string('source',50);$t->unsignedBigInteger('source_sync_run_id')->nullable();$t->decimal('total_ordered_qty',18,4)->default(0);$t->decimal('total_delivered_qty',18,4)->default(0);$t->decimal('total_missing_qty',18,4)->default(0);$t->decimal('historical_shortfall_amount',18,2)->default(0);$t->string('currency_id',10)->nullable();$t->json('metadata')->nullable();$t->timestamps();});
  Schema::create('fulfillment_history_lines',function(Blueprint $t){$t->id();$t->foreignId('snapshot_id')->constrained('fulfillment_history_snapshots')->cascadeOnDelete();$t->unsignedInteger('line_nbr')->default(0);$t->string('inventory_id',100);$t->string('description',500)->nullable();$t->decimal('order_qty',18,4);$t->decimal('delivered_qty',18,4);$t->decimal('cancelled_qty',18,4)->default(0);$t->decimal('open_qty',18,4);$t->boolean('open_qty_explicit')->default(false);$t->decimal('unit_price',18,4);$t->decimal('shortfall_amount',18,2);$t->string('uom',30)->nullable();$t->timestamps();$t->unique(['snapshot_id','line_nbr','inventory_id'],'fulfillment_history_line_identity');});
 }
 public function down(): void {Schema::dropIfExists('fulfillment_history_lines');Schema::dropIfExists('fulfillment_history_snapshots');}
};
