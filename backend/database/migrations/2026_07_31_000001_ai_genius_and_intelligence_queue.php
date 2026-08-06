<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_intelligence_briefings', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_intelligence_briefings', 'error_message')) {
                $table->text('error_message')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('ai_intelligence_briefings', 'model')) {
                $table->string('model', 80)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('ai_intelligence_briefings', 'queue_uuid')) {
                $table->uuid('queue_uuid')->nullable()->index();
            }
            if (! Schema::hasColumn('ai_intelligence_briefings', 'generated_by_user_id')) {
                $table->unsignedBigInteger('generated_by_user_id')->nullable()->index();
            }
        });

        Schema::create('ai_genius_briefings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consultant_user_id')->index();
            $table->date('week_start');
            $table->json('insights')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->string('ai_status', 30)->default('queued');
            $table->string('provider', 30)->nullable();
            $table->string('model', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('queue_uuid')->nullable()->index();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('generated_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['consultant_user_id', 'week_start'], 'ai_genius_consultant_week_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_genius_briefings');

        Schema::table('ai_intelligence_briefings', function (Blueprint $table) {
            foreach (['error_message', 'model', 'queue_uuid', 'generated_by_user_id'] as $col) {
                if (Schema::hasColumn('ai_intelligence_briefings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
