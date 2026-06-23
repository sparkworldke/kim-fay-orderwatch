<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->longText('body_content')->nullable()->after('body_preview');
            $table->string('conversation_id', 512)->nullable()->after('message_id');
            $table->string('internet_message_id', 1000)->nullable()->after('conversation_id');
            $table->string('match_classification', 40)->nullable()->after('matched_order_id');
            $table->json('match_sources')->nullable()->after('match_classification');
            $table->json('match_evidence')->nullable()->after('match_sources');
            $table->json('match_conflicts')->nullable()->after('match_evidence');
            $table->json('match_reason_codes')->nullable()->after('match_conflicts');
            $table->string('match_rule_version', 30)->nullable()->after('match_reason_codes');
            $table->string('reviewer_decision', 40)->nullable()->after('match_rule_version');
            $table->text('reviewer_reason')->nullable()->after('reviewer_decision');
            $table->foreignId('reviewed_by')->nullable()->after('reviewer_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['mailbox_account_id', 'conversation_id']);
            $table->index('match_classification');
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->cascadeOnDelete();
            $table->string('graph_attachment_id', 512);
            $table->string('name', 1000)->nullable();
            $table->string('content_type', 255)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_inline')->default(false);
            $table->longText('extracted_text')->nullable();
            $table->string('extraction_status', 30)->default('pending');
            $table->unsignedTinyInteger('extraction_confidence')->nullable();
            $table->string('extraction_method', 50)->nullable();
            $table->text('extraction_error')->nullable();
            $table->timestamps();

            $table->unique(['email_id', 'graph_attachment_id']);
        });

        Schema::create('email_match_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('acumatica_sales_orders')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rule_version', 30);
            $table->string('searched_po', 100)->nullable();
            $table->json('candidates');
            $table->json('sources');
            $table->json('normalization');
            $table->json('conflicts');
            $table->json('reason_codes');
            $table->string('classification', 40);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_match_attempts');
        Schema::dropIfExists('email_attachments');
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['mailbox_account_id', 'conversation_id']);
            $table->dropIndex(['match_classification']);
            $table->dropColumn([
                'body_content', 'conversation_id', 'internet_message_id', 'match_classification',
                'match_sources', 'match_evidence', 'match_conflicts', 'match_reason_codes',
                'match_rule_version', 'reviewer_decision', 'reviewer_reason', 'reviewed_by', 'reviewed_at',
            ]);
        });
    }
};
