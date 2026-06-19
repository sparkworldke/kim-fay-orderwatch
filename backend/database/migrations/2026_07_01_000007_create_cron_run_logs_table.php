<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cron_job_id')->constrained('cron_jobs')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status'); // 'success' | 'failure'
            $table->text('output')->nullable(); // first 500 chars
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_run_logs');
    }
};
