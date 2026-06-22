<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_account_id')->constrained('mailbox_accounts')->cascadeOnDelete();
            $table->string('message_id', 512)->unique();
            $table->string('subject', 1000)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->json('to_recipients')->nullable();
            $table->string('body_preview', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->string('folder', 100)->default('Inbox');
            $table->timestamps();

            $table->index(['mailbox_account_id', 'received_at']);
            $table->index('from_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
