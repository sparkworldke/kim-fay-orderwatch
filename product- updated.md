Curated Product Catalog: new model, CSV import, editable admin UI
Context
The Inventory/Production/Partner/Sales pages currently derive brand and category straight from Acumatica's raw sync data (AcumaticaInventoryItem.brand / .item_class). category in particular is the raw Acumatica ItemClass string (e.g. "FINGOODS -SKINCARE -GEL -BIO"), which is why filter dropdowns show ugly, inconsistent values instead of the clean groupings the business actually uses (Item Group, Trading Group, etc., as seen in the Stock Items BI(Data).csv export). The user wants a separate, admin-curated Product catalog — populated by CSV upload (processed in the background) and editable by hand — that becomes the authoritative source for these classification fields, matched onto the existing Acumatica-synced inventory by Inventory ID.

Interesting existing fact discovered during research: AcumaticaInventoryItem already has dormant columns (item_group, sub_item_group, trading_group, sub_trading_group, conversion_factor, profit_margin_target, supplier) that the Acumatica sync never writes to (confirmed in AcumaticaInventorySyncService::upsertItem() — its updateOrCreate() payload never touches them). Only brand, product_category_id, and default_uom are sync-managed (with existing-value fallback for brand via ProductBrandClassifier). This means a manually-curated table is safe from being clobbered by the nightly sync as long as it's a separate table, which also matches what the user explicitly asked for ("create a new model for product").

CSV → field mapping (confirmed with user, using Stock Items BI(Data).csv header order: Inventory ID, Description, Item Class, Posting Class, Brand, Description, Item Group, Sub Item Group, Trading Group, Sub Trading Group, Conversion Factor, UOM, Profit Margin Target, Supplier):

CSV column (by position)	Product field	Notes
Inventory ID	inventory_id	unique key, joins to acumatica_inventory_items.inventory_id
Description (1st)	name	item name
Item Class	item_class	raw, for reference only
Posting Class	posting_class	
Brand	brand	
Description (2nd)	category_path	e.g. "FG-Skin Care-Gel-Bio Oil" — informational label
Item Group	category	per user: this feeds the frontend "category" filter
Sub Item Group	sub_category	
Trading Group	brand_ownership	normalize: "Kimfay Brand" → manufactured, "Partners" → partner (matches BrandOwnership type in src/types/Stock/inventory.ts)
Sub Trading Group	trading_group	per user: this feeds the "Trading Group" field (values: Trading / Manufactured)
Conversion Factor	conversion_factor	decimal
UOM	uom	
Profit Margin Target	profit_margin_target	parse "30%" → 0.30 decimal
Supplier	supplier	
The CSV has two columns named "Description" — the parser must map by column position, not by header-name lookup, or the second will clobber the first.

Backend
1. New model + migration
backend/database/migrations/xxxx_create_products_table.php: table products with columns above (inventory_id unique/indexed) + source (csv_import|manual), last_imported_at, updated_by (nullable FK to users), timestamps.
backend/app/Models/Product.php: fillable list matching the columns; casts() for conversion_factor/profit_margin_target as decimal; no relation needed to AcumaticaInventoryItem (joined by app code, not FK, since inventory_id isn't a PK on either side).
2. CSV import — background job + progress log
Mirror the existing ProcessDtcPriceExcelImportJob / DtcSyncLog pattern (backend/app/Jobs/ProcessDtcPriceExcelImportJob.php, backend/app/Models/DtcSyncLog.php), but actually dispatch to the queue (unlike the Dtc controller, which calls its import service synchronously despite having a job) — the user explicitly asked for background processing.

New migration + model ProductImportLog (id, status queued|running|completed|failed, file_name, total_rows, processed_rows, created_count, updated_count, error_count, errors (json, capped sample), triggered_by_user_id, started_at, finished_at, error_message, timestamps).
New service backend/app/Services/Admin/ProductCsvImportService.php:
loadRows($path, $extension) — for .csv use fgetcsv (like FolProductsController::bulkUpload); for .xlsx use PhpOffice\PhpSpreadsheet\IOFactory (like DtcPriceExcelImportService).
Positional column mapping per the table above (detect header row, map by index not name, tolerate the duplicate "Description" header).
import(string $path, string $extension, ProductImportLog $log): void — for each row, Product::updateOrCreate(['inventory_id' => ...], [...]) with source = 'csv_import', last_imported_at = now(); update $log->processed_rows/created_count/updated_count incrementally (e.g. every 100 rows) so polling shows progress; catch per-row errors into errors[] (cap sample like FolProductsController's $missing cap of 25) and continue.
New job backend/app/Jobs/ImportProductsCsvJob.php implements ShouldQueue (timeout ~900s, tries 1, like ProcessDtcPriceExcelImportJob) — sets log to running, calls the service, sets completed/failed + finished_at.
3. Controller + routes
New backend/app/Http/Controllers/Api/Admin/ProductsController.php (mirror FolProductsController's shape/style, use App\Services\Admin\AuditLogger for product_updated/product_csv_import_queued audit events):

index(Request) — paginated list with q search (inventory_id/name), filters (brand, category, trading_group).
update(Request, string $inventoryId) — validate + updateOrCreate a single product (source = 'manual', updated_by = $user->id); this is how "make the products editable" is served.
import(Request) — validate file (csv/xlsx, size cap), store to storage/app/imports/products, create ProductImportLog (status: queued), dispatch ImportProductsCsvJob, return the log.
importJobs(Request) — history list.
importJobStatus(Request, ProductImportLog $log) — single-log poll endpoint.
Register in backend/routes/api.php inside the existing Route::prefix('admin')->middleware('admin.or.cs')->group(...) block (same group as fol/products, ~line 334-509):

Route::get('products', [ProductsController::class, 'index']);
Route::put('products/{inventoryId}', [ProductsController::class, 'update']);
Route::post('products/import', [ProductsController::class, 'import']);
Route::get('products/import-jobs', [ProductsController::class, 'importJobs']);
Route::get('products/import-jobs/{log}', [ProductsController::class, 'importJobStatus']);
4. Wire curated data into the inventory read path
Edit backend/app/Services/Production/ProductionIntelligenceService.php:

In inventory() and sales(), bulk-fetch Product::whereIn('inventory_id', $ids)->get()->keyBy('inventory_id') once and pass the map into inventoryRow()/the sales loop.
In inventoryRow() (line 121) and the sales() row-building (line 91): 'brand' => $product?->brand ?: $item->brand, 'category' => $product?->category ?: $item->item_class (fallback keeps items not yet in the CSV working). Add new keys to the row payload for calculations: sub_category, trading_group, conversion_factor, uom, profit_margin_target, supplier (all from $product, null when absent).
In ownership() (line 182): prefer $product?->brand_ownership before the existing productionPlan/product_type fallback chain.
In filterOptions() (line 211): source categories from $product->category (fallback to item_class for un-imported items) instead of raw item_class, so the frontend MultiSelect category filter shows clean values.
Explicitly out of scope for this change: FillRateBusinessCategory, ProductBrandClassifier, and any other module reading AcumaticaInventoryItem.trading_group/.item_group directly — those are pre-existing, unrelated to this feature, and not touched.

Frontend
5. Admin "Products" panel
src/routes/app.administration.tsx is one large file with an ADMIN_TABS array (~line 137) and per-tab panel components in the same file (e.g. AcumaticaPanel line 749). Add:

A new ADMIN_TABS entry ({ value: "products", label: "Products", perm: ..., panel: ProductsPanel }) gated the same way as neighboring admin-only tabs.
ProductsPanel component with:
Search/filter bar (brand, category, trading group) + paginated table (Inventory ID, Name, Brand, Category, Sub Category, Trading Group, Ownership, Conversion Factor, UOM, Profit Margin Target, Supplier, Edit button).
Edit modal reusing the Radix Dialog pattern from src/components/production/MsiEditDialog.tsx (form → PUT /admin/products/{inventoryId}).
CSV upload: hidden <input type="file"> triggered by a button, same pattern as importSalesOrders in this same file (~line 255) — FormData + fetch(... , { headers: { Authorization: Bearer } }) → POST /admin/products/import.
After upload, poll GET /admin/products/import-jobs/{id} every ~2s until status is completed/failed, then show the summary (created/updated/errors) and refresh the table.
Small history list below sourced from GET /admin/products/import-jobs.
No changes needed to src/types/Stock/inventory.ts or inventory.service.ts — the Production/Partner/Sales pages already consume brand/category/brandOwnership from the API in the same shape; they'll automatically show the curated values once the backend wiring (step 4) lands.

Verification
php artisan migrate — confirm products and product_import_logs tables created.
Seed one CSV import via POST /admin/products/import (or the new UI) using the exact Stock Items BI(Data).csv the user provided; confirm the duplicate-"Description" column doesn't corrupt name/category_path.
Ensure a queue worker is running (php artisan queue:work --once or check the existing worker process) so the ImportProductsCsvJob actually processes; poll the returned log id and confirm it reaches completed with correct created/updated counts (spot check against known SKUs, e.g. COSTP0030 → category "Toilet Tissue" via Item Group, trading_group "Manufactured" via Sub Trading Group, brand_ownership "manufactured" via Trading Group="Kimfay Brand").
Hit GET /inventory (Production Intelligence) and confirm category/brand now reflect curated values for imported SKUs, and un-imported SKUs still show the old fallback (no regressions/blank values).
Edit a product via the new admin UI, confirm it persists (source = 'manual') and is reflected on the next GET /inventory call.
Re-run php artisan acumatica:sync-inventory (or trigger via admin UI) and confirm curated products rows are untouched (proves isolation from the nightly sync).