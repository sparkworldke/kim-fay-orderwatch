<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fol_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('fol_requests', 'requestor_contact_id')) {
                $table->foreignId('requestor_contact_id')
                    ->nullable()
                    ->after('requestor_email')
                    ->constrained('customer_contacts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fol_requests', function (Blueprint $table) {
            if (Schema::hasColumn('fol_requests', 'requestor_contact_id')) {
                $table->dropConstrainedForeignId('requestor_contact_id');
            }
        });
    }
};
