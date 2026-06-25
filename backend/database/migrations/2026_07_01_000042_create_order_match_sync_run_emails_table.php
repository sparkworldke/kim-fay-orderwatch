<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_match_sync_runs', function (Blueprint $table) {
            $table->unsignedInteger('emails_created')->default(0)->after('emails_found');
            $table->unsignedInteger('emails_updated')->default(0)->after('emails_created');
        });

        Schema::create('order_match_sync_run_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_match_sync_run_id')->constrained('order_match_sync_runs')->cascadeOnDelete();
            $table->foreignId('email_id')->constrained('emails')->cascadeOnDelete();
            $table->string('outcome', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_match_sync_run_id', 'email_id'], 'sync_run_email_unique');
            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_match_sync_run_emails');
        Schema::table('order_match_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['emails_created', 'emails_updated']);
        });
    }
};