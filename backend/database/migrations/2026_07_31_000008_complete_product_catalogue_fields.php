<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'acumatica_inventory_item_id')) Schema::table('products', function (Blueprint $table) {
            $table->foreignId('acumatica_inventory_item_id')->nullable()->unique()->after('id')->constrained('acumatica_inventory_items')->nullOnDelete();
        });
        if (! Schema::hasColumn('products', 'source_description')) Schema::table('products', fn (Blueprint $table) => $table->string('source_description')->nullable()->after('category_path'));
        if (! Schema::hasColumn('products', 'portfolio_group')) Schema::table('products', fn (Blueprint $table) => $table->string('portfolio_group')->nullable()->after('source_description'));
        if (! Schema::hasColumn('products', 'ownership')) Schema::table('products', fn (Blueprint $table) => $table->enum('ownership', ['manufactured', 'partner'])->nullable()->after('portfolio_group'));
        if (! Schema::hasColumn('products', 'is_active')) Schema::table('products', fn (Blueprint $table) => $table->boolean('is_active')->default(true)->after('ownership'));
        if (! Schema::hasColumn('products', 'import_locked')) Schema::table('products', fn (Blueprint $table) => $table->boolean('import_locked')->default(false)->after('is_active'));
        if (! Schema::hasColumn('products', 'manually_edited_at')) Schema::table('products', fn (Blueprint $table) => $table->timestamp('manually_edited_at')->nullable()->after('last_imported_at'));

        if (! Schema::hasColumn('product_import_logs', 'file_path')) Schema::table('product_import_logs', fn (Blueprint $table) => $table->string('file_path', 500)->nullable()->after('file_name'));
        if (! Schema::hasColumn('product_import_logs', 'skipped_count')) Schema::table('product_import_logs', fn (Blueprint $table) => $table->unsignedInteger('skipped_count')->default(0)->after('updated_count'));
        if (! Schema::hasColumn('product_import_logs', 'unmatched_count')) Schema::table('product_import_logs', fn (Blueprint $table) => $table->unsignedInteger('unmatched_count')->default(0)->after('skipped_count'));

        // Preserve existing planning decisions as the initial editable catalogue ownership.
        DB::statement(
            "UPDATE products SET ownership = (
                SELECT psp.ownership FROM production_sku_plans psp
                JOIN acumatica_inventory_items aii ON aii.id = psp.inventory_item_id
                WHERE aii.inventory_id = products.inventory_id
                LIMIT 1
            ) WHERE ownership IS NULL"
        );
    }

    public function down(): void
    {
        Schema::table('product_import_logs', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'skipped_count', 'unmatched_count']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acumatica_inventory_item_id');
            $table->dropColumn([
                'source_description', 'portfolio_group', 'ownership', 'is_active',
                'import_locked', 'manually_edited_at',
            ]);
        });
    }
};
