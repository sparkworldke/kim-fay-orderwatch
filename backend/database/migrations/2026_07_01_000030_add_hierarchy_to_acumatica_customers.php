<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            // Acumatica ID of the parent/main account; null = standalone or is the main account
            $table->string('parent_acumatica_id', 50)->nullable()->after('acumatica_id');
            // True when this record IS the main/head account for a group of branches
            $table->boolean('is_main_account')->default(false)->after('parent_acumatica_id');

            $table->index('parent_acumatica_id');
            $table->index('is_main_account');
            $table->index('customer_class');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            $table->dropIndex(['parent_acumatica_id']);
            $table->dropIndex(['is_main_account']);
            $table->dropIndex(['customer_class']);
            $table->dropColumn(['parent_acumatica_id', 'is_main_account']);
        });
    }
};
