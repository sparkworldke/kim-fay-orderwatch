<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtc_quotes', function (Blueprint $table) {
            $table->string('ship_to_phone', 30)->nullable()->after('customer_name');
            $table->string('ship_to_email', 255)->nullable()->after('ship_to_phone');
            $table->string('ship_to_address_line1', 255)->nullable()->after('ship_to_email');
            $table->string('ship_to_address_line2', 255)->nullable()->after('ship_to_address_line1');
        });
    }

    public function down(): void
    {
        Schema::table('dtc_quotes', function (Blueprint $table) {
            $table->dropColumn(['ship_to_phone', 'ship_to_email', 'ship_to_address_line1', 'ship_to_address_line2']);
        });
    }
};
