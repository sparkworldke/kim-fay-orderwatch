<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_account_id')->constrained('mailbox_accounts')->cascadeOnDelete();
            $table->string('external_folder_id', 512);
            $table->string('display_name', 255);
            $table->string('parent_external_folder_id', 512)->nullable();
            $table->string('parent_display_name', 255)->nullable();
            $table->unsignedInteger('total_item_count')->default(0);
            $table->unsignedInteger('unread_item_count')->default(0);
            $table->boolean('is_sync_enabled')->default(false);
            $table->boolean('is_order_folder')->default(false);
            $table->foreignId('customer_id')->nullable()->constrained('acumatica_customers')->nullOnDelete();
            $table->string('trust_level', 30)->default('untrusted');
            $table->unsignedSmallInteger('sync_priority')->default(100);
            $table->longText('delta_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_discovered_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_account_id', 'external_folder_id'], 'mailbox_folder_external_unique');
            $table->index(['mailbox_account_id', 'is_sync_enabled', 'is_active'], 'mailbox_folder_sync_index');
        });

        Schema::create('mailbox_rule_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_account_id')->constrained('mailbox_accounts')->cascadeOnDelete();
            $table->foreignId('mailbox_folder_id')->constrained('mailbox_folders')->cascadeOnDelete();
            $table->string('existing_rule_name', 255);
            $table->foreignId('customer_id')->nullable()->constrained('acumatica_customers')->nullOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_trusted')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mailbox_folder_id', 'is_enabled']);
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique('emails_message_id_unique');
            $table->foreignId('mailbox_folder_id')->nullable()->after('mailbox_account_id')->constrained('mailbox_folders')->nullOnDelete();
            $table->string('external_folder_id', 512)->nullable()->after('mailbox_folder_id');
            $table->string('ingestion_classification', 30)->nullable()->after('folder');
            $table->json('ingestion_reason_codes')->nullable()->after('ingestion_classification');
            $table->json('ingestion_decision_sources')->nullable()->after('ingestion_reason_codes');
            $table->string('ingestion_review_status', 30)->nullable()->after('ingestion_decision_sources');
            $table->text('ingestion_review_reason')->nullable()->after('ingestion_review_status');
            $table->foreignId('ingestion_reviewed_by')->nullable()->after('ingestion_review_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('ingestion_reviewed_at')->nullable()->after('ingestion_reviewed_by');
            $table->unique(['mailbox_account_id', 'message_id'], 'emails_mailbox_message_unique');
            $table->index(['mailbox_folder_id', 'ingestion_classification'], 'emails_folder_ingestion_index');
        });

        Schema::table('mailbox_sync_item_logs', function (Blueprint $table) {
            $table->foreignId('mailbox_folder_id')->nullable()->after('mailbox_sync_log_id')->constrained('mailbox_folders')->nullOnDelete();
            $table->foreignId('email_id')->nullable()->after('mailbox_folder_id')->constrained('emails')->nullOnDelete();
            $table->string('decision_source', 100)->nullable()->after('reason');
            $table->boolean('po_number_detected')->default(false)->after('decision_source');
            $table->string('po_number_source', 100)->nullable()->after('po_number_detected');
            $table->json('decision_context')->nullable()->after('po_number_source');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_sync_item_logs', function (Blueprint $table) {
            $table->dropForeign(['mailbox_folder_id']);
            $table->dropForeign(['email_id']);
            $table->dropColumn(['mailbox_folder_id', 'email_id', 'decision_source', 'po_number_detected', 'po_number_source', 'decision_context']);
        });
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique('emails_mailbox_message_unique');
            $table->dropForeign(['mailbox_folder_id']);
            $table->dropForeign(['ingestion_reviewed_by']);
            $table->dropIndex('emails_folder_ingestion_index');
            $table->dropColumn([
                'mailbox_folder_id', 'external_folder_id', 'ingestion_classification', 'ingestion_reason_codes',
                'ingestion_decision_sources', 'ingestion_review_status', 'ingestion_review_reason', 'ingestion_reviewed_by', 'ingestion_reviewed_at',
            ]);
            $table->unique('message_id');
        });
        Schema::dropIfExists('mailbox_rule_mappings');
        Schema::dropIfExists('mailbox_folders');
    }
};
