<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_brand_assignments', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('brand')->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_brand_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
