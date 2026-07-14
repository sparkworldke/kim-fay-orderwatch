<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('so_reason_parents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('so_sub_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('so_reason_parent_sub_reason', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('so_reason_parents')->cascadeOnDelete();
            $table->foreignId('sub_reason_id')->constrained('so_sub_reasons')->cascadeOnDelete();
            $table->unique(['parent_id', 'sub_reason_id']);
        });

        Schema::create('so_reason_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 120);
            $table->string('sub_reason_code', 80);
            $table->unique('alias');
            $table->index('sub_reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('so_reason_aliases');
        Schema::dropIfExists('so_reason_parent_sub_reason');
        Schema::dropIfExists('so_sub_reasons');
        Schema::dropIfExists('so_reason_parents');
    }
};