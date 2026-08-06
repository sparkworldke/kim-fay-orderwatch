<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel DTC Sales Prices export fields + brand for catalog filters.
 * Match key remains inventory_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtc_price_list', function (Blueprint $table) {
            $table->string('price_type', 80)->nullable()->after('price_code');
            $table->string('taxation', 80)->nullable()->after('currency_id')
                ->comment('Tax Category from Acumatica/Excel e.g. TAXABLE');
            $table->string('tax', 80)->nullable()->after('taxation');
            $table->string('brand', 120)->nullable()->after('description');
            $table->string('product_type', 80)->nullable()->after('brand');
            $table->decimal('break_qty', 18, 4)->nullable()->after('dtc_price');
            $table->date('effective_date')->nullable()->after('break_qty');
            $table->date('expiration_date')->nullable()->after('effective_date');
            $table->string('source', 40)->nullable()->after('synced_at')
                ->comment('acumatica_inquiry|excel|product_sync');

            $table->index('brand');
            $table->index('taxation');
            $table->index('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('dtc_price_list', function (Blueprint $table) {
            $table->dropIndex(['brand']);
            $table->dropIndex(['taxation']);
            $table->dropIndex(['product_type']);
            $table->dropColumn([
                'price_type',
                'taxation',
                'tax',
                'brand',
                'product_type',
                'break_qty',
                'effective_date',
                'expiration_date',
                'source',
            ]);
        });
    }
};
