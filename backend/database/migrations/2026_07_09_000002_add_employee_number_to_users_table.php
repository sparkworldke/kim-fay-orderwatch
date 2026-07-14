<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'employee_number')) {
                $table->string('employee_number', 50)->nullable()->after('rep_code')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'employee_number')) {
                $table->dropIndex(['employee_number']);
                $table->dropColumn('employee_number');
            }
        });
    }
};
