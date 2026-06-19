<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('acumatica_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type'); // values: 'sales_orders', 'customers'
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->integer('record_count')->default(0);
            $table->string('status'); // values: 'running', 'completed', 'failed'
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acumatica_sync_logs');
    }
};
