<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_reconciliation_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_run_id');
            $table->string('resource_type', 50); // 'customer', 'sales_order', 'category'
            $table->string('resource_id', 100);   // acumatica ID of the record
            $table->string('field_name', 100);
            $table->text('local_value')->nullable();
            $table->text('acumatica_value')->nullable();
            $table->string('severity', 20)->default('warning'); // 'info', 'warning', 'error'
            $table->string('remediation_status', 20)->default('open'); // 'open', 'resolved', 'ignored'
            $table->timestamps();

            $table->index(['sync_run_id', 'resource_type']);
            $table->index('remediation_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_reconciliation_results');
    }
};
