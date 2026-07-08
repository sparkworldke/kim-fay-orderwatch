<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_sync_logs', function (Blueprint $table) {
            $table->date('sync_from')->nullable();
            $table->date('sync_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['sync_from', 'sync_to']);
        });
    }
};
