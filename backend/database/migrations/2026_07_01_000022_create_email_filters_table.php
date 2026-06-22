<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_filters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('type', 50); // sender_email | sender_domain | subject_keyword
            $table->string('value', 500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_filters');
    }
};
