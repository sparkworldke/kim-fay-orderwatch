<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kp_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->string('customer_acumatica_id', 50)->nullable()->index();
            $table->string('customer_name', 255)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 30)->default('scheduled'); // scheduled|completed|cancelled
            $table->string('outlook_event_id', 200)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'status']);
            $table->index(['owner_user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kp_meetings');
    }
};
