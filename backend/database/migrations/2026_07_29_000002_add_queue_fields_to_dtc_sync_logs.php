<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dtc_sync_logs', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->after('status');
            $table->string('original_filename')->nullable()->after('storage_path');
            $table->uuid('queue_job_id')->nullable()->after('original_filename')->index();
            $table->json('progress')->nullable()->after('records_processed');
        });
    }

    public function down(): void
    {
        Schema::table('dtc_sync_logs', function (Blueprint $table) {
            $table->dropIndex(['queue_job_id']);
            $table->dropColumn(['storage_path', 'original_filename', 'queue_job_id', 'progress']);
        });
    }
};
