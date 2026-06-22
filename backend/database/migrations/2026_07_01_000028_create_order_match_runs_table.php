<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("order_match_runs", function (Blueprint $table) {
            $table->id();
            $table->foreignId("triggered_by_user_id")->nullable()->constrained("users")->nullOnDelete();
            $table->timestamp("started_at");
            $table->timestamp("ended_at")->nullable();
            $table->string("status", 20)->default("running");
            $table->unsignedInteger("emails_processed")->default(0);
            $table->unsignedInteger("po_extracted")->default(0);
            $table->unsignedInteger("matched")->default(0);
            $table->unsignedInteger("unmatched")->default(0);
            $table->unsignedInteger("duplicate")->default(0);
            $table->unsignedInteger("missing_in_acumatica")->default(0);
            $table->text("error_message")->nullable();
            $table->json("summary")->nullable();
            $table->timestamps();
            $table->index("status");
            $table->index("started_at");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("order_match_runs");
    }
};
