<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('acumatica_configs', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 500)->default('https://kimfay.acumatica.com');
            $table->string('endpoint', 100)->default('IpayV2');
            $table->string('version', 50)->default('22.200.001');
            $table->string('tenant', 255)->default('Kim-Fay Limited');
            $table->string('grant_type', 50)->default('password');
            $table->string('scope', 50)->default('api');
            $table->string('username', 255);
            $table->text('password_encrypted');
            $table->text('client_id_encrypted')->nullable();
            $table->text('client_secret_encrypted')->nullable();
            $table->string('token_url', 500)->default('https://kimfay.acumatica.com/identity/connect/token');
            $table->string('endpoint_version', 50)->nullable();
            $table->timestamp('last_validated_at')->nullable();
            // Using string() for SQLite compatibility (no native ENUM support)
            $table->string('health_status')->default('unchecked'); // values: connected, error, unchecked
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acumatica_configs');
    }
};
