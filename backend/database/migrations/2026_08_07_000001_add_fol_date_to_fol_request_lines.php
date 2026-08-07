<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fol_request_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('fol_request_lines', 'fol_date')) {
                $table->date('fol_date')->nullable()->after('date_last_issue');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fol_request_lines', function (Blueprint $table) {
            if (Schema::hasColumn('fol_request_lines', 'fol_date')) {
                $table->dropColumn('fol_date');
            }
        });
    }
};
