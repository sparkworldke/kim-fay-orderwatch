<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('customer_acumatica_id', 50)->index();
            $table->string('designation_key', 50);
            $table->string('designation_label', 120);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_acumatica_id', 'is_active']);
            $table->index(['customer_acumatica_id', 'designation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
