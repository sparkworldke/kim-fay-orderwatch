<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone migration for the user_acumatica_rep_mappings table.
 *
 * This table was originally created inside the bundled migration
 * 2026_07_13_000001_create_team_management_tables.php. Extracting it
 * into its own migration lets it run independently on environments where
 * the bundled migration may not have executed.
 *
 * The up() method is idempotent: if the table already exists (e.g. the
 * bundled migration already ran locally) it is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_acumatica_rep_mappings')) {
            return;
        }

        Schema::create('user_acumatica_rep_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('acumatica_consultant_id', 50)->nullable();
            $table->string('acumatica_rep_code', 50)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_acumatica_rep_mappings');
    }
};
