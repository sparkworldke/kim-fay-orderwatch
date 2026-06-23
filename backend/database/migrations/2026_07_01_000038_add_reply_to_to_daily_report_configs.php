<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_report_configs', function (Blueprint $table) {
            $table->json('reply_to_json')->nullable()->after('recipients_json');
        });

        Schema::table('daily_report_delivery_logs', function (Blueprint $table) {
            $table->string('recipient_role', 10)->default('to')->after('recipient_email');
        });

        DB::table('daily_report_configs')
            ->whereNull('reply_to_json')
            ->update(['reply_to_json' => json_encode([])]);
    }

    public function down(): void
    {
        Schema::table('daily_report_delivery_logs', function (Blueprint $table) {
            $table->dropColumn('recipient_role');
        });

        Schema::table('daily_report_configs', function (Blueprint $table) {
            $table->dropColumn('reply_to_json');
        });
    }
};