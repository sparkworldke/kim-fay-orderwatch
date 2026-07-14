<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow multi-role: replace single-user unique with (user_id, role_id).
        // MySQL blocks DROP UNIQUE while the column is a foreign key.
        if (Schema::hasTable('user_roles')) {
            // Prefer raw MySQL so we do not depend on doctrine FK discovery names.
            try {
                DB::statement('ALTER TABLE `user_roles` DROP FOREIGN KEY `user_roles_user_id_foreign`');
            } catch (\Throwable) {
            }
            try {
                DB::statement('ALTER TABLE `user_roles` DROP FOREIGN KEY `user_roles_role_id_foreign`');
            } catch (\Throwable) {
            }
            try {
                DB::statement('ALTER TABLE `user_roles` DROP INDEX `user_roles_user_id_unique`');
            } catch (\Throwable) {
            }
            try {
                DB::statement('ALTER TABLE `user_roles` ADD UNIQUE `user_roles_user_id_role_id_unique` (`user_id`, `role_id`)');
            } catch (\Throwable) {
            }
            try {
                DB::statement('ALTER TABLE `user_roles` ADD CONSTRAINT `user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE');
            } catch (\Throwable) {
            }
            try {
                DB::statement('ALTER TABLE `user_roles` ADD CONSTRAINT `user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE');
            } catch (\Throwable) {
            }
        }

        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('acumatica_inventory_items', 'is_fol_eligible')) {
                $table->boolean('is_fol_eligible')->default(false)->after('item_status');
            }
            if (! Schema::hasColumn('acumatica_inventory_items', 'fol_category')) {
                $table->string('fol_category', 50)->nullable()->after('is_fol_eligible');
            }
        });

        if (! Schema::hasTable('fol_approval_stages')) {
        Schema::create('fol_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('assignee_mode', 50)->default('role');
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('require_comment')->default(true);
            $table->unsignedInteger('sla_hours')->nullable();
            $table->timestamps();
        });

        Schema::create('fol_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 30)->unique();
            $table->string('customer_acumatica_id', 50);
            $table->string('customer_name', 255);
            $table->foreignId('sales_consultant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sales_consultant_email')->nullable();
            $table->string('sales_consultant_rep_code', 50)->nullable();
            $table->string('request_origin', 50);
            $table->string('request_origin_other')->nullable();
            $table->string('requestor_first_name', 100);
            $table->string('requestor_last_name', 100);
            $table->string('requestor_phone', 50);
            $table->string('requestor_email', 255);
            $table->json('issue_types');
            $table->text('reason_text');
            $table->boolean('installation_required')->default(false);
            $table->text('installation_location')->nullable();
            $table->boolean('customer_has_submitted_po')->default(false);
            $table->date('consumables_last_purchase_date')->nullable();
            $table->decimal('consumables_sales_6m_kes', 15, 2)->default(0);
            $table->decimal('consumables_volume_6m', 15, 4)->default(0);
            $table->string('consumables_metrics_source', 30)->default('system_so');
            $table->text('consumables_override_reason')->nullable();
            $table->text('debt_explanation');
            $table->string('status', 50)->default('draft');
            $table->string('current_stage_key', 50)->nullable();
            $table->json('linked_so_order_nbrs')->nullable();
            $table->string('linked_so_status_summary', 100)->nullable();
            $table->json('form_json')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_acumatica_id', 'status']);
            $table->index(['sales_consultant_user_id', 'status']);
            $table->index('current_stage_key');
        });

        Schema::create('fol_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fol_request_id')->constrained('fol_requests')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('inventory_id', 100);
            $table->string('product_description', 500)->nullable();
            $table->decimal('qty_requested', 15, 4);
            $table->decimal('qty_previously_issued', 15, 4)->default(0);
            $table->date('date_last_issue')->nullable();
            $table->string('previous_source', 50)->default('prior_fol');
            $table->json('commitment_sku_ids')->nullable();
            $table->timestamps();

            $table->unique(['fol_request_id', 'line_no']);
            $table->index(['inventory_id']);
        });

        Schema::create('fol_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fol_request_id')->constrained('fol_requests')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fol_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fol_request_id')->constrained('fol_requests')->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['fol_request_id', 'event_type']);
        });

        Schema::create('fol_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fol_request_id')->constrained('fol_requests')->cascadeOnDelete();
            $table->string('stage_key', 50);
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 20);
            $table->text('comment');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['fol_request_id', 'stage_key']);
        });

        Schema::create('fol_so_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fol_request_id')->constrained('fol_requests')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('acumatica_sales_orders')->nullOnDelete();
            $table->string('acumatica_order_nbr', 50);
            $table->string('link_type', 30)->default('invoice');
            $table->timestamp('matched_at');
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fol_request_id', 'acumatica_order_nbr']);
        });
        } // end if !fol_approval_stages
    }

    public function down(): void
    {
        Schema::dropIfExists('fol_so_links');
        Schema::dropIfExists('fol_approval_actions');
        Schema::dropIfExists('fol_request_events');
        Schema::dropIfExists('fol_request_attachments');
        Schema::dropIfExists('fol_request_lines');
        Schema::dropIfExists('fol_requests');
        Schema::dropIfExists('fol_approval_stages');

        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            if (Schema::hasColumn('acumatica_inventory_items', 'fol_category')) {
                $table->dropColumn('fol_category');
            }
            if (Schema::hasColumn('acumatica_inventory_items', 'is_fol_eligible')) {
                $table->dropColumn('is_fol_eligible');
            }
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropUnique('user_roles_user_id_role_id_unique');
            $table->unique('user_id');
        });
    }
};
