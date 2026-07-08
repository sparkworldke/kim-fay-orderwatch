<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('acumatica_sales_orders', 'sales_consultant_rep_code')) {
                $table->string('sales_consultant_rep_code', 50)->nullable()->after('currency_id')->index();
            }

            if (! Schema::hasColumn('acumatica_sales_orders', 'sales_consultant_name')) {
                $table->string('sales_consultant_name', 255)->nullable()->after('sales_consultant_rep_code');
            }

            if (! Schema::hasColumn('acumatica_sales_orders', 'import_source')) {
                $table->string('import_source', 50)->nullable()->after('sales_consultant_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('acumatica_sales_orders', 'sales_consultant_rep_code')) {
                $table->dropIndex(['sales_consultant_rep_code']);
            }

            $drop = array_values(array_filter([
                Schema::hasColumn('acumatica_sales_orders', 'sales_consultant_rep_code') ? 'sales_consultant_rep_code' : null,
                Schema::hasColumn('acumatica_sales_orders', 'sales_consultant_name') ? 'sales_consultant_name' : null,
                Schema::hasColumn('acumatica_sales_orders', 'import_source') ? 'import_source' : null,
            ]));

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
