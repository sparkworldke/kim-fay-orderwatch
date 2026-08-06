<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_id')->unique();
            $table->string('name')->nullable();
            $table->string('item_class')->nullable();
            $table->string('posting_class')->nullable();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('sub_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('category_path')->nullable();
            $table->foreignId('trading_group_id')->nullable()->constrained('trading_groups')->nullOnDelete();
            $table->decimal('conversion_factor', 14, 4)->nullable();
            $table->string('uom')->nullable();
            $table->decimal('profit_margin_target', 6, 4)->nullable();
            $table->string('supplier')->nullable();
            $table->enum('source', ['csv_import', 'manual'])->default('manual');
            $table->timestamp('last_imported_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
