<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fol_requests', function (Blueprint $table) {
            $table->json('consumable_inventory_ids')->nullable()->after('customer_has_submitted_po');
            $table->decimal('consumables_sales_3m_kes', 18, 2)->default(0)->after('consumables_last_purchase_date');
            $table->decimal('consumables_volume_3m', 18, 4)->default(0)->after('consumables_sales_3m_kes');
            $table->json('consumables_evidence_json')->nullable()->after('consumables_volume_6m');
            $table->timestamp('consumables_metrics_as_of')->nullable()->after('consumables_evidence_json');
        });

        Schema::table('fol_request_lines', function (Blueprint $table) {
            $table->string('line_type', 20)->default('fol_item')->after('product_description');
        });
    }

    public function down(): void
    {
        Schema::table('fol_request_lines', function (Blueprint $table) {
            $table->dropColumn('line_type');
        });

        Schema::table('fol_requests', function (Blueprint $table) {
            $table->dropColumn([
                'consumable_inventory_ids',
                'consumables_sales_3m_kes',
                'consumables_volume_3m',
                'consumables_evidence_json',
                'consumables_metrics_as_of',
            ]);
        });
    }
};
