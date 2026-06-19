<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 50)->unique();
            $table->string('label', 255);
            $table->json('channels');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_dispatch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('notification_rules')->cascadeOnDelete();
            $table->timestamp('evaluated_at');
            $table->string('channel'); // 'email', 'in_app'
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('delivery_status'); // 'queued', 'delivered', 'failed'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatch_logs');
        Schema::dropIfExists('notification_rules');
    }
};
