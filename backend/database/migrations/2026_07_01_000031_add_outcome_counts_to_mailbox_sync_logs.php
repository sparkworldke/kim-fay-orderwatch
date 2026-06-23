<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_sync_logs', function (Blueprint $table) {
            $table->unsignedInteger('emails_created')->default(0)->after('emails_fetched');
            $table->unsignedInteger('emails_updated')->default(0)->after('emails_created');
            $table->unsignedInteger('emails_skipped')->default(0)->after('emails_updated');
            $table->unsignedInteger('emails_deleted')->default(0)->after('emails_skipped');
            $table->unsignedInteger('emails_failed')->default(0)->after('emails_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_sync_logs', function (Blueprint $table) {
            $table->dropColumn([
                'emails_created',
                'emails_updated',
                'emails_skipped',
                'emails_deleted',
                'emails_failed',
            ]);
        });
    }
};
