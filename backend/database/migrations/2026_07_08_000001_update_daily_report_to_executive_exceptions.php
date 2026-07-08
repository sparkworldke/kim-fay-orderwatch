<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('daily_report_configs')->update([
            'name' => 'Daily Executive Exceptions Report',
            'subject_template' => 'OrderWatch Executive Exceptions – {report_date}',
            'include_ai_insights' => false,
            'include_comparison' => false,
            'include_mtd' => false,
            'include_customer_highlights' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('daily_report_configs')->update([
            'name' => 'Daily Management Report',
            'subject_template' => 'OrderWatch Daily Brief – {report_date}',
            'include_ai_insights' => true,
            'include_comparison' => true,
            'include_mtd' => true,
            'include_customer_highlights' => true,
            'updated_at' => now(),
        ]);
    }
};