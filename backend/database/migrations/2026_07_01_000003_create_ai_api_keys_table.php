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
        Schema::create('ai_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique(); // 'openai' | 'anthropic'
            $table->text('key_encrypted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('health_status')->default('healthy'); // 'healthy' | 'rate_limited' | 'error'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_api_keys');
    }
};
