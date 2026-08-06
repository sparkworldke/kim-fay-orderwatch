<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64); // backorders | fill_rate | inventory
            $table->string('status', 32)->default('queued'); // queued|processing|ready|failed|expired
            $table->string('filename', 255)->nullable();
            $table->string('disk', 32)->default('local');
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->json('filters')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_downloads');
    }
};
