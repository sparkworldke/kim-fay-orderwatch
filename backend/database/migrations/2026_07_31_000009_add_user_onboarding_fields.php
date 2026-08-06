<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable()->after('phone_number');
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });

        // Do not unexpectedly force established accounts through first-login onboarding.
        DB::table('users')->whereNull('password_changed_at')->update([
            'password_changed_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'password_changed_at']);
        });
    }
};
