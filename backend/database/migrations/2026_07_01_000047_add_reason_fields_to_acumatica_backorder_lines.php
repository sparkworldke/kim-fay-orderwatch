<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->string('reason_code', 80)->nullable()->after('fulfillment_status');
            $table->text('reason_notes')->nullable()->after('reason_code');
            $table->unsignedBigInteger('reason_updated_by')->nullable()->after('reason_notes');
            $table->timestamp('reason_updated_at')->nullable()->after('reason_updated_by');
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->dropIndex(['reason_code']);
            $table->dropColumn([
                'reason_code',
                'reason_notes',
                'reason_updated_by',
                'reason_updated_at',
            ]);
        });
    }
};
