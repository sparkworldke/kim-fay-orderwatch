<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sfa_customers', function (Blueprint $table) {
            $table->string('suggested_acumatica_customer_id', 50)->nullable()->after('acumatica_customer_id')->index();
            $table->decimal('match_score', 5, 2)->nullable()->after('match_status');
            $table->string('match_method', 40)->nullable()->after('match_score');
            $table->unsignedBigInteger('matched_by')->nullable()->after('matched_at');
            $table->string('pilot_segment', 30)->nullable()->index();
        });

        Schema::create('sfa_customer_match_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sfa_customer_id')->index();
            $table->string('previous_acumatica_customer_id', 50)->nullable();
            $table->string('acumatica_customer_id', 50)->nullable();
            $table->string('action', 30)->index();
            $table->string('method', 40)->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfa_customer_match_audits');
        Schema::table('sfa_customers', fn (Blueprint $table) => $table->dropColumn([
            'suggested_acumatica_customer_id', 'match_score', 'match_method', 'matched_by', 'pilot_segment',
        ]));
    }
};
