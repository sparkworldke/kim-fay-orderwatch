<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_customer_categories', function (Blueprint $table) {
            $table->id();
            $table->string('acumatica_id', 50)->unique(); // ClassID
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_customer_categories');
    }
};
