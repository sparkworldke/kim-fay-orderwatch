<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->timestamp('first_backordered_at')->nullable()->after('requested_on')->index();
            $table->boolean('first_backordered_at_is_backfilled')->default(false)->after('first_backordered_at');
        });

        DB::table('acumatica_backorder_lines')
            ->where('shortfall_kind', 'active_backorder')
            ->whereNull('first_backordered_at')
            ->update([
                'first_backordered_at' => DB::raw('COALESCE(synced_at, created_at)'),
                'first_backordered_at_is_backfilled' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->dropIndex(['first_backordered_at']);
            $table->dropColumn(['first_backordered_at', 'first_backordered_at_is_backfilled']);
        });
    }
};
