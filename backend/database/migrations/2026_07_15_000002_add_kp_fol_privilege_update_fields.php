<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fol_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('fol_requests', 'assigned_technician_user_id')) {
                $table->foreignId('assigned_technician_user_id')
                    ->nullable()
                    ->after('installation_location')
                    ->index('fol_requests_assigned_technician_idx')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('fol_requests', 'technician_assigned_by')) {
                $table->foreignId('technician_assigned_by')
                    ->nullable()
                    ->after('assigned_technician_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('fol_requests', 'technician_assigned_at')) {
                $table->timestamp('technician_assigned_at')->nullable()->after('technician_assigned_by');
            }
        });

        Schema::table('fol_so_links', function (Blueprint $table) {
            if (! Schema::hasColumn('fol_so_links', 'po_number')) {
                $table->string('po_number', 100)->nullable()->after('acumatica_order_nbr');
            }

            $table->index('link_type', 'fol_so_links_link_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fol_so_links', function (Blueprint $table) {
            $table->dropIndex('fol_so_links_link_type_idx');

            if (Schema::hasColumn('fol_so_links', 'po_number')) {
                $table->dropColumn('po_number');
            }
        });

        Schema::table('fol_requests', function (Blueprint $table) {
            if (Schema::hasColumn('fol_requests', 'assigned_technician_user_id')) {
                $table->dropForeign(['assigned_technician_user_id']);
                $table->dropIndex('fol_requests_assigned_technician_idx');
                $table->dropColumn('assigned_technician_user_id');
            }

            if (Schema::hasColumn('fol_requests', 'technician_assigned_by')) {
                $table->dropForeign(['technician_assigned_by']);
                $table->dropColumn('technician_assigned_by');
            }

            if (Schema::hasColumn('fol_requests', 'technician_assigned_at')) {
                $table->dropColumn('technician_assigned_at');
            }
        });
    }
};
