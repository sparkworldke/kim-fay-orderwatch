<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Portfolio Attribution data model (PRD §8.2, §7.10).
 *
 * Adds time-aware, deterministic attribution to the existing
 * user_customer_assignments table and introduces the rule catalogue,
 * attribution audit trail, sales-channel catalogue, department HOD history,
 * and team migration batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createAssignmentRulesTable();
        $this->extendCustomerAssignments();
        $this->createAttributionAuditsTable();
        $this->createSalesChannelsCatalogue();
        $this->createDepartmentHodAssignments();
        $this->createTeamMigrationBatches();
        $this->extendCustomerMaster();
    }

    public function down(): void
    {
        $this->dropCustomerMasterExtension();
        Schema::dropIfExists('team_migration_batches');
        Schema::dropIfExists('department_hod_assignments');
        Schema::dropIfExists('sales_channels');
        Schema::dropIfExists('customer_attribution_audits');
        $this->revertCustomerAssignments();
        Schema::dropIfExists('customer_assignment_rules');
    }

    private function createAssignmentRulesTable(): void
    {
        if (Schema::hasTable('customer_assignment_rules')) {
            return;
        }

        Schema::create('customer_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // customer | main_account | region | rep_alias
            $table->string('rule_type', 20);
            // Normalized customer ID, main-account name, region, or identifier.
            $table->string('match_value', 191);
            $table->json('secondary_match_json')->nullable();

            // Resolution precedence (lower wins). Mirrors config('attribution.precedence').
            $table->unsignedSmallInteger('priority')->default(3);

            // manual | excel | acumatica | seeder
            $table->string('source', 20)->default('manual');
            $table->string('source_batch_id')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason')->nullable();
            $table->timestamps();

            $table->index(['rule_type', 'match_value']);
            $table->index(['user_id', 'rule_type']);
            $table->index('source_batch_id');
        });
    }

    private function extendCustomerAssignments(): void
    {
        if (! Schema::hasTable('user_customer_assignments')) {
            return;
        }

        Schema::table('user_customer_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('user_customer_assignments', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('source_batch_id');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'priority')) {
                $table->unsignedSmallInteger('priority')
                    ->default(config('attribution.default_assignment_priority', 2))
                    ->after('effective_to');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'is_manual_override')) {
                $table->boolean('is_manual_override')->default(false)->after('priority');
            }
        });

        // FK to the rule that produced the assignment (added after the rules table exists).
        if (! Schema::hasColumn('user_customer_assignments', 'assignment_rule_id')) {
            Schema::table('user_customer_assignments', function (Blueprint $table) {
                $table->foreignId('assignment_rule_id')
                    ->nullable()
                    ->after('is_manual_override')
                    ->constrained('customer_assignment_rules')
                    ->nullOnDelete();
            });
        }

        // Single active servicing assignment per customer is enforced in code; an
        // index speeds up effective-resolution lookups.
        $this->ensureIndex('user_customer_assignments', 'uca_customer_priority_idx', ['customer_acumatica_id', 'assignment_type', 'priority']);
        $this->ensureIndex('user_customer_assignments', 'uca_effective_dates_idx', ['effective_from', 'effective_to']);
    }

    private function createAttributionAuditsTable(): void
    {
        if (Schema::hasTable('customer_attribution_audits')) {
            return;
        }

        Schema::create('customer_attribution_audits', function (Blueprint $table) {
            $table->id();
            $table->string('customer_acumatica_id', 50);
            $table->foreignId('prior_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Winning source/rule (manual_override | workbook_customer | main_account | region | ...).
            $table->string('winning_source', 30)->nullable();
            $table->string('winning_rule_type', 20)->nullable();
            $table->foreignId('assignment_rule_id')->nullable()->constrained('customer_assignment_rules')->nullOnDelete();

            $table->json('competing_candidates')->nullable();
            $table->text('resolution_reason')->nullable();

            $table->string('actor_type', 20)->default('system'); // system | user | job
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_batch_id')->nullable();
            $table->timestamps();

            $table->index('customer_acumatica_id');
            $table->index('new_user_id');
        });
    }

    private function createSalesChannelsCatalogue(): void
    {
        if (Schema::hasTable('sales_channels')) {
            return;
        }

        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('parent_code', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('department_slug', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('parent_code');
        });

        $now = now();
        $channels = [
            ['MT', 'Modern Trade', null, 10, 'mt_consumer_sales', 'Parent of MT1 and MT2'],
            ['MT1', 'Modern Trade Tier 1', 'MT', 11, 'mt_consumer_sales', 'Main accounts Majid, Naivas, Quick Mart'],
            ['MT2', 'Modern Trade Tier 2', 'MT', 12, 'mt_consumer_sales', 'Explicit MT2 outlets'],
            ['GT', 'General Trade', null, 20, 'gt', null],
            ['DTC_DTB', 'Direct-to-Consumer / Direct-to-Business', null, 30, null, 'Definition pending approval'],
            ['ECOMMERCE', 'E-commerce', null, 40, null, 'Digital commerce customers'],
            ['KP', 'Kim-Fay Professional', null, 50, 'kp', 'Protected by the KP CRM access gate'],
        ];

        foreach ($channels as [$code, $name, $parent, $sort, $dept, $notes]) {
            DB::table('sales_channels')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'parent_code' => $parent,
                    'sort_order' => $sort,
                    'department_slug' => $dept,
                    'notes' => $notes,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    private function createDepartmentHodAssignments(): void
    {
        if (Schema::hasTable('department_hod_assignments')) {
            return;
        }

        Schema::create('department_hod_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('hod_user_id')->constrained('users')->cascadeOnDelete();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'is_active']);
            $table->index('hod_user_id');
        });
    }

    private function createTeamMigrationBatches(): void
    {
        if (Schema::hasTable('team_migration_batches')) {
            return;
        }

        Schema::create('team_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('source_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('destination_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('old_hod_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_hod_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('operation', 20)->default('transfer_members'); // replace_hod | transfer_members
            $table->json('member_ids')->nullable();             // selected user IDs
            $table->json('include_subtrees')->nullable();       // per-member subtree decisions
            $table->json('secondary_membership_decisions')->nullable();

            $table->json('preview_statistics')->nullable();
            $table->json('validation_errors')->nullable();

            $table->date('effective_date')->nullable();
            $table->string('change_reason')->nullable();
            // preview | applied | failed | rolled_back
            $table->string('status', 20)->default('preview');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    private function extendCustomerMaster(): void
    {
        if (! Schema::hasTable('acumatica_customers')) {
            return;
        }

        Schema::table('acumatica_customers', function (Blueprint $table) {
            if (! Schema::hasColumn('acumatica_customers', 'main_account_name')) {
                $table->string('main_account_name', 191)->nullable()->after('parent_acumatica_id');
            }
            if (! Schema::hasColumn('acumatica_customers', 'sales_channel_code')) {
                $table->string('sales_channel_code', 30)->nullable()->after('customer_class');
            }
        });
    }

    private function dropCustomerMasterExtension(): void
    {
        if (! Schema::hasTable('acumatica_customers')) {
            return;
        }

        Schema::table('acumatica_customers', function (Blueprint $table) {
            foreach (['sales_channel_code', 'main_account_name'] as $column) {
                if (Schema::hasColumn('acumatica_customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function revertCustomerAssignments(): void
    {
        if (! Schema::hasTable('user_customer_assignments')) {
            return;
        }

        Schema::table('user_customer_assignments', function (Blueprint $table) {
            foreach (['assignment_rule_id', 'is_manual_override', 'priority', 'effective_to', 'effective_from'] as $column) {
                if (Schema::hasColumn('user_customer_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('{$table}')") as $row) {
                $name = is_object($row) ? ($row->name ?? '') : ($row['name'] ?? '');
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $indexName],
        ) !== null;
    }
};
