<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_import_configs', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('display_name')
                ->constrained('acumatica_customers')->nullOnDelete();
            $table->string('match_mode', 20)->default('exact')->after('sender_pattern');
            $table->string('branch_name', 255)->nullable()->after('customer_id');
            $table->string('branch_tag_pattern', 500)->nullable()->after('branch_name');
            $table->string('approval_status', 20)->default('approved')->after('is_active');
            $table->foreignId('created_by')->nullable()->after('approval_status')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('last_matched_at')->nullable()->after('approved_at');
            $table->timestamp('last_imported_at')->nullable()->after('last_matched_at');
            $table->timestamp('auto_deactivated_at')->nullable()->after('last_imported_at');
            $table->json('guardrail_metadata')->nullable()->after('auto_deactivated_at');

            $table->index(['approval_status', 'is_active']);
            $table->index(['match_mode', 'is_active']);
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('email_import_config_id')->nullable()->after('mailbox_folder_id')
                ->constrained('email_import_configs')->nullOnDelete();
            $table->foreignId('matched_customer_id')->nullable()->after('email_import_config_id')
                ->constrained('acumatica_customers')->nullOnDelete();
            $table->string('matched_branch_tag', 120)->nullable()->after('matched_customer_id');
            $table->string('import_match_strategy', 20)->nullable()->after('matched_branch_tag');
            $table->string('import_guardrail_status', 30)->nullable()->after('import_match_strategy');
            $table->string('import_guardrail_reason', 500)->nullable()->after('import_guardrail_status');

            $table->index(['matched_customer_id', 'received_at']);
            $table->index(['import_guardrail_status', 'received_at']);
            $table->index(['message_id', 'from_email']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['matched_customer_id', 'received_at']);
            $table->dropIndex(['import_guardrail_status', 'received_at']);
            $table->dropIndex(['message_id', 'from_email']);
            $table->dropColumn([
                'email_import_config_id',
                'matched_customer_id',
                'matched_branch_tag',
                'import_match_strategy',
                'import_guardrail_status',
                'import_guardrail_reason',
            ]);
        });

        Schema::table('email_import_configs', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'is_active']);
            $table->dropIndex(['match_mode', 'is_active']);
            $table->dropColumn([
                'customer_id',
                'match_mode',
                'branch_name',
                'branch_tag_pattern',
                'approval_status',
                'created_by',
                'approved_by',
                'approved_at',
                'last_matched_at',
                'last_imported_at',
                'auto_deactivated_at',
                'guardrail_metadata',
            ]);
        });
    }
};
