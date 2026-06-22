<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->string('match_status', 20)->default('pending')->after('status'); // pending | matched | unmatched | partial
            $table->boolean('is_flagged')->default(false)->after('match_status');
            $table->text('rejection_reason')->nullable()->after('is_flagged');
            $table->text('on_hold_reason')->nullable()->after('rejection_reason');
            $table->string('email_subject', 1000)->nullable()->after('on_hold_reason');
            $table->timestamp('email_received_at')->nullable()->after('email_subject');

            $table->index('match_status');
            $table->index('is_flagged');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['match_status']);
            $table->dropIndex(['is_flagged']);
            $table->dropColumn(['match_status', 'is_flagged', 'rejection_reason', 'on_hold_reason', 'email_subject', 'email_received_at']);
        });
    }
};
