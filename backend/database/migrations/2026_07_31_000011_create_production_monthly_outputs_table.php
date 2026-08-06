<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_monthly_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('acumatica_inventory_items')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('qty_produced', 18, 4)->nullable();
            $table->string('source', 40)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['inventory_item_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_monthly_outputs');
    }
};
