<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            if (! Schema::hasColumn('emails', 'extracted_po_number')) {
                $table->string('extracted_po_number', 100)->nullable()->after('folder');
            }
            if (! Schema::hasColumn('emails', 'po_extraction_method')) {
                $table->string('po_extraction_method', 50)->nullable()->after('extracted_po_number');
            }
            if (! Schema::hasColumn('emails', 'po_extraction_confidence')) {
                $table->tinyInteger('po_extraction_confidence')->nullable()->after('po_extraction_method');
            }
            if (! Schema::hasColumn('emails', 'matched_order_id')) {
                $table->foreignId('matched_order_id')
                    ->nullable()
                    ->after('po_extraction_confidence')
                    ->constrained('acumatica_sales_orders')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('emails', 'has_attachments')) {
                $table->boolean('has_attachments')->default(false)->after('matched_order_id');
            }
            if (! Schema::hasColumn('emails', 'po_extraction_attempted')) {
                $table->boolean('po_extraction_attempted')->default(false)->after('has_attachments');
            }
        });

        // Indexes added with try-catch to avoid errors if they already exist
        try { Schema::table('emails', fn ($t) => $t->index('extracted_po_number')); } catch (\Throwable) {}
        try { Schema::table('emails', fn ($t) => $t->index('po_extraction_attempted')); } catch (\Throwable) {}
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['matched_order_id']);
            $table->dropIndex(['extracted_po_number']);
            $table->dropIndex(['po_extraction_attempted']);
            $table->dropIndex(['matched_order_id']);
            $table->dropColumn([
                'extracted_po_number', 'po_extraction_method', 'po_extraction_confidence',
                'matched_order_id', 'has_attachments', 'po_extraction_attempted',
            ]);
        });
    }
};
