<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_run_id');
            $table->string('resource_type', 50); // 'customer', 'sales_order', 'category'
            $table->string('resource_id', 100)->nullable();
            $table->integer('attempt_count')->default(1);
            $table->text('last_error');
            $table->longText('raw_payload')->nullable(); // JSON
            $table->text('remediation_notes')->nullable();
            $table->timestamps();

            $table->index(['sync_run_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_dead_letters');
    }
};
