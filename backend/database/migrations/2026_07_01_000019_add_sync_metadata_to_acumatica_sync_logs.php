<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sync_logs', function (Blueprint $table) {
            $table->integer('success_count')->default(0)->after('record_count');
            $table->integer('failed_count')->default(0)->after('success_count');
            $table->text('filters')->nullable()->after('failed_count');     // JSON
            $table->string('trigger_type', 30)->default('manual')->after('filters'); // 'manual', 'background', 'scheduled'
            $table->unsignedBigInteger('triggered_by_user_id')->nullable()->after('trigger_type');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['success_count', 'failed_count', 'filters', 'trigger_type', 'triggered_by_user_id']);
        });
    }
};
