<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rule_email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_rule_id')->constrained('notification_rules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['notification_rule_id', 'user_id'], 'notif_rule_email_recipient_unique');
        });

        Schema::create('notification_rule_role_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_rule_id')->constrained('notification_rules')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['notification_rule_id', 'role_id'], 'notif_rule_role_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rule_role_recipients');
        Schema::dropIfExists('notification_rule_email_recipients');
    }
};
