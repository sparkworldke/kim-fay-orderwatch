<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_downloads', function (Blueprint $table) {
            $table->string('recipient_email')->nullable()->after('user_id');
            $table->string('delivery_mode', 20)->default('dashboard')->after('recipient_email');
            $table->string('public_token', 64)->nullable()->unique()->after('delivery_mode');
            $table->timestamp('emailed_at')->nullable()->after('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('export_downloads', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['recipient_email', 'delivery_mode', 'public_token', 'emailed_at']);
        });
    }
};
