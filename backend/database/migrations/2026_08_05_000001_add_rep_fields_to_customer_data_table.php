<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_data', function (Blueprint $table) {
            $table->string('rep_code', 50)->nullable()->after('main_ac_owner')->index();
            $table->string('sales_rep', 150)->nullable()->after('rep_code');
        });
    }

    public function down(): void
    {
        Schema::table('customer_data', function (Blueprint $table) {
            $table->dropIndex(['rep_code']);
            $table->dropColumn(['rep_code', 'sales_rep']);
        });
    }
};
