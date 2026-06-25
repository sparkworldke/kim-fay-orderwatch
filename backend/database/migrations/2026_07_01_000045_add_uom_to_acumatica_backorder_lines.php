<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->string('uom', 20)->nullable()->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->dropColumn('uom');
        });
    }
};