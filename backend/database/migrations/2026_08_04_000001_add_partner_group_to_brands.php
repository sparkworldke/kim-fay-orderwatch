<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->foreignId('partner_brand_group_id')
                ->nullable()
                ->after('ownership')
                ->constrained('trading_groups')
                ->nullOnDelete();
            $table->index(['ownership', 'partner_brand_group_id'], 'brands_partner_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex('brands_partner_group_idx');
            $table->dropConstrainedForeignId('partner_brand_group_id');
        });
    }
};
