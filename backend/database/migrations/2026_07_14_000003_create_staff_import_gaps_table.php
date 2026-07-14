<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_import_gaps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('employee_number', 20)->nullable();
            $table->string('display_name')->nullable();
            $table->string('gap_reason', 40);
            $table->decimal('match_score', 4, 3)->nullable();
            $table->json('source_payload')->nullable();
            $table->string('resolution_status', 20)->default('open');
            $table->foreignId('resolved_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('resolution_status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_import_gaps');
    }
};