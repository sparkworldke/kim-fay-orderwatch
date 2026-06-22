<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['is_flagged']);
            $table->dropColumn('is_flagged');
            // null = no flag, 'acumatica' = missing/issue in Acumatica, 'email' = missing/issue in email
            $table->string('flag_source', 20)->nullable()->after('match_status');
            $table->index('flag_source');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['flag_source']);
            $table->dropColumn('flag_source');
            $table->boolean('is_flagged')->default(false)->after('match_status');
            $table->index('is_flagged');
        });
    }
};
