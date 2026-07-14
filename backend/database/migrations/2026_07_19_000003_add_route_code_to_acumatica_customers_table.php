<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            if (! Schema::hasColumn('acumatica_customers', 'route_code')) {
                $table->string('route_code', 50)->nullable()->after('shipping_zone_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            if (Schema::hasColumn('acumatica_customers', 'route_code')) {
                $table->dropIndex(['route_code']);
                $table->dropColumn('route_code');
            }
        });
    }
};
