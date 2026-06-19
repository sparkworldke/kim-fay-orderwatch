<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->text('description')->nullable();
            $table->string('cron_expression', 100);
            $table->string('command', 1000);
            $table->string('status')->default('active'); // 'active', 'paused'
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status')->nullable(); // 'success', 'failure'
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
