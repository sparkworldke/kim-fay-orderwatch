<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sync_logs', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable()->after('ended_at');
            $table->timestamp('stop_requested_at')->nullable()->after('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['heartbeat_at', 'stop_requested_at']);
        });
    }
};
