<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->string('workflow_parent_reason', 40)->nullable()->after('on_hold_reason');
            $table->string('workflow_sub_reason_code', 80)->nullable()->after('workflow_parent_reason');
            $table->string('workflow_reason_label', 160)->nullable()->after('workflow_sub_reason_code');

            $table->index('workflow_parent_reason');
            $table->index('workflow_sub_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['workflow_parent_reason']);
            $table->dropIndex(['workflow_sub_reason_code']);
            $table->dropColumn([
                'workflow_parent_reason',
                'workflow_sub_reason_code',
                'workflow_reason_label',
            ]);
        });
    }
};