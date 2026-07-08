<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->string('rejection_reason_code', 80)->nullable()->after('rejection_reason');
            $table->index('rejection_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['rejection_reason_code']);
            $table->dropColumn('rejection_reason_code');
        });
    }
};
