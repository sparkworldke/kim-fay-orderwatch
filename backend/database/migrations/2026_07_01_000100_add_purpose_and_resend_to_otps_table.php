<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->string('purpose')->default('login')->after('email'); // login, password-reset, welcome
            $table->tinyInteger('resend_attempts')->unsigned()->default(0)->after('attempts');
            $table->timestamp('resend_window_start')->nullable()->after('resend_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'resend_attempts', 'resend_window_start']);
        });
    }
};
