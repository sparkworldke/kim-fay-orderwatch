<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_sync_logs', function (Blueprint $table) {
            $table->foreignId('email_filter_id')
                ->nullable()
                ->after('mailbox_account_id')
                ->constrained('email_filters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_sync_logs', function (Blueprint $table) {
            $table->dropForeign(['email_filter_id']);
            $table->dropColumn('email_filter_id');
        });
    }
};
