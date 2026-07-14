<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sku_insights', function (Blueprint $table) {
            $table->id(); // BIGINT PK AUTO_INCREMENT
            $table->string('inventory_id', 50)->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->json('ai_response');
            $table->string('ai_status', 20);
            $table->json('data_gaps')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['inventory_id', 'date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sku_insights');
    }
};
