<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_data', function (Blueprint $table) {
            $table->id();
            // 1:1 link to the Acumatica customer master.
            $table->string('customer_acumatica_id', 50)->unique();

            // Delivery routing references.
            $table->string('route_code', 50)->nullable();
            $table->string('shipping_zone_id', 50)->nullable();
            $table->string('customer_zone', 100)->nullable();

            // Commercial / classification fields from the Excel export.
            $table->string('customer_group', 100)->nullable();
            $table->string('tax_registration_id', 100)->nullable();
            $table->string('currency_id', 50)->nullable();
            $table->string('price_class_id', 50)->nullable();
            $table->string('price_class_name', 150)->nullable();
            $table->string('main_ac_owner', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('customer_region', 100)->nullable();
            $table->string('sage_code', 50)->nullable();
            $table->string('business_account_id', 50)->nullable();

            // Financial fields.
            $table->decimal('credit_limit', 16, 2)->nullable();
            $table->string('statement_type', 50)->nullable();
            $table->string('statement_cycle', 50)->nullable();
            $table->string('shipping_rule', 50)->nullable();
            $table->string('delivery', 50)->nullable();

            // Address fields captured from the export (the customer master stores
            // these as JSON; these mirror the raw structured lines for reporting).
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address_line_1', 255)->nullable();
            $table->string('address_line_2', 255)->nullable();
            $table->string('address_line_3', 255)->nullable();
            $table->string('email', 255)->nullable();

            // Provenance metadata.
            $table->string('created_by', 150)->nullable();
            $table->timestamp('created_on')->nullable();
            $table->string('source', 50)->default('excel_upload');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('route_code');
            $table->index('shipping_zone_id');
            $table->index('customer_group');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_data');
    }
};
