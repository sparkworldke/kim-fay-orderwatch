<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_role', 50)->nullable();
            $table->text('prompt');
            $table->string('intent', 100)->nullable();
            $table->json('domains')->nullable();
            $table->json('formulas_used')->nullable();
            $table->json('db_query_scope')->nullable();
            $table->text('ai_message')->nullable();
            $table->json('cards_returned')->nullable();
            $table->json('sources')->nullable();
            $table->string('provider', 50)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('intent');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_logs');
    }
};
