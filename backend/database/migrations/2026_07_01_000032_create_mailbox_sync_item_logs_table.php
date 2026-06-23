<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_sync_item_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_sync_log_id')->constrained('mailbox_sync_logs')->cascadeOnDelete();
            $table->string('message_id', 512)->nullable();
            $table->string('outcome', 20);
            $table->string('reason', 100)->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['mailbox_sync_log_id', 'outcome']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_sync_item_logs');
    }
};
