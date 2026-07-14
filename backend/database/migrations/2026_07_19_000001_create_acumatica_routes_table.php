<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_routes', function (Blueprint $table) {
            $table->id();
            $table->string('route_code', 50)->unique();
            $table->string('route_name', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('shipping_zone_id', 50)->nullable();
            $table->string('customer_zone', 100)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('route_name');
            $table->index('shipping_zone_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_routes');
    }
};
