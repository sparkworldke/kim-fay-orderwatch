<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_customer_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('user_customer_assignments', 'source')) {
                $table->string('source', 40)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'source_batch_id')) {
                $table->string('source_batch_id', 36)->nullable()->after('source');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'last_so_date')) {
                $table->date('last_so_date')->nullable()->after('source_batch_id');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'so_order_count')) {
                $table->unsignedInteger('so_order_count')->nullable()->after('last_so_date');
            }
            if (! Schema::hasColumn('user_customer_assignments', 'confidence')) {
                $table->unsignedTinyInteger('confidence')->nullable()->after('so_order_count');
            }
        });

        Schema::create('customer_assignment_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source', 40);
            $table->string('mode', 40)->default('add_only');
            $table->string('status', 20)->default('dry_run');
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename')->nullable();
            $table->json('stats_json')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['source', 'status']);
            $table->index('target_user_id');
        });

        Schema::create('customer_assignment_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('customer_assignment_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no')->default(0);
            $table->string('rep_code', 80)->nullable();
            $table->string('customer_acumatica_id', 80)->nullable();
            $table->string('customer_name')->nullable();
            $table->foreignId('resolved_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->string('status', 20);
            $table->string('source', 40);
            $table->text('message')->nullable();
            $table->json('details_json')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'status']);
            $table->index('customer_acumatica_id');
            $table->index('resolved_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_assignment_batch_rows');
        Schema::dropIfExists('customer_assignment_batches');

        Schema::table('user_customer_assignments', function (Blueprint $table) {
            $columns = [
                'source',
                'source_batch_id',
                'last_so_date',
                'so_order_count',
                'confidence',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('user_customer_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
