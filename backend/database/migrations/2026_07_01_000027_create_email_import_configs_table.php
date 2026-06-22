<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("email_import_configs", function (Blueprint $table) {
            $table->id();
            $table->string("sender_pattern", 500);
            $table->boolean("is_wildcard")->default(false);
            $table->string("display_name", 255);
            $table->string("customer_class", 100)->nullable();
            $table->json("po_patterns")->nullable();
            $table->string("po_extraction_source", 20)->default("all");
            $table->boolean("ai_fallback_enabled")->default(true);
            $table->boolean("is_active")->default(true);
            $table->text("notes")->nullable();
            $table->timestamps();
            $table->index("is_active");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("email_import_configs");
    }
};
