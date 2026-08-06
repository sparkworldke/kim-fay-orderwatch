<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->enum('source', ['csv_import', 'manual'])->default('manual');
            $table->timestamps();

            $table->unique(['name', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
