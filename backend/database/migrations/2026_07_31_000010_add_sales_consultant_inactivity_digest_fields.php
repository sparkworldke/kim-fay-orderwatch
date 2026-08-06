<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('inactivity_digest_enabled')->default(false)->after('password_changed_at');
            $table->timestamp('last_inactivity_digest_sent_at')->nullable()->after('inactivity_digest_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['inactivity_digest_enabled', 'last_inactivity_digest_sent_at']);
        });
    }
};
