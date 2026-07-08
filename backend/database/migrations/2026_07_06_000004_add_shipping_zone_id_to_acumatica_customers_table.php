<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            if (! Schema::hasColumn('acumatica_customers', 'shipping_zone_id')) {
                $table->string('shipping_zone_id', 50)->nullable()->after('tax_zone')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            if (Schema::hasColumn('acumatica_customers', 'shipping_zone_id')) {
                $table->dropIndex(['shipping_zone_id']);
                $table->dropColumn('shipping_zone_id');
            }
        });
    }
};