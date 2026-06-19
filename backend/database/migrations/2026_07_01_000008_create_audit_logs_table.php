<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('timestamp', 6);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_ip', 45)->nullable();
            $table->string('action_type', 100);
            $table->string('resource_type', 100);
            $table->string('resource_id', 255)->nullable();
            $table->json('changes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
