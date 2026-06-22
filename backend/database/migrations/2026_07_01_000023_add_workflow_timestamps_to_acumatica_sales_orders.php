<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('order_date');
            $table->timestamp('shipped_at')->nullable()->after('approved_at');
            $table->timestamp('completed_at')->nullable()->after('shipped_at');

            $table->index('approved_at');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['approved_at']);
            $table->dropIndex(['completed_at']);
            $table->dropColumn(['approved_at', 'shipped_at', 'completed_at']);
        });
    }
};
