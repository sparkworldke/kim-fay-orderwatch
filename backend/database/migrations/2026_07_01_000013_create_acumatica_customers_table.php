<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_customers', function (Blueprint $table) {
            $table->id();
            $table->string('acumatica_id', 50)->unique(); // e.g. "CUST101239"
            $table->string('name', 255);
            $table->string('status', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('customer_class', 50)->nullable();
            $table->string('payment_terms', 50)->nullable();
            $table->string('tax_zone', 50)->nullable();
            $table->text('billing_address')->nullable();  // JSON
            $table->text('shipping_address')->nullable(); // JSON
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('acumatica_last_modified')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_customers');
    }
};
