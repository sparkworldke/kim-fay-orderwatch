<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These columns are added by 2026_07_01_000026_add_po_extraction_fields_to_emails.php
        // This file is kept only to satisfy Laravel's migration state tracker.
        Schema::table('emails', function (Blueprint $table) {
            if (! Schema::hasColumn('emails', 'extracted_po_number')) {
                $table->string('extracted_po_number', 100)->nullable()->after('folder');
            }
            if (! Schema::hasColumn('emails', 'po_extraction_method')) {
                $table->string('po_extraction_method', 30)->nullable()->after('extracted_po_number');
            }
            if (! Schema::hasColumn('emails', 'po_extraction_confidence')) {
                $table->tinyInteger('po_extraction_confidence')->unsigned()->nullable()->after('po_extraction_method');
            }
            if (! Schema::hasColumn('emails', 'matched_order_id')) {
                $table->unsignedBigInteger('matched_order_id')->nullable()->after('po_extraction_confidence');
                $table->foreign('matched_order_id')
                    ->references('id')
                    ->on('acumatica_sales_orders')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty — managed by add_po_extraction_fields_to_emails migration
    }
};
