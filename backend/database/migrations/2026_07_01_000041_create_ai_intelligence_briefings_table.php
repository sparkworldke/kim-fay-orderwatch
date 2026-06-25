<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_intelligence_briefings', function (Blueprint $table) {
            $table->id();
            $table->date('date_from');
            $table->date('date_to');
            $table->longText('insights');
            $table->string('ai_status', 30)->default('success');
            $table->string('provider', 30)->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_intelligence_briefings');
    }
};