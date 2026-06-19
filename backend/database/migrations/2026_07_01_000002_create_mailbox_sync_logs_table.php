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
        Schema::create('mailbox_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_account_id')->constrained('mailbox_accounts')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->integer('emails_fetched')->default(0);
            $table->string('status'); // 'running', 'completed', 'failed' — string for SQLite compat
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_sync_logs');
    }
};
