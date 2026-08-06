<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\DtcPriceList;
use App\Models\DtcQuote;
use App\Models\DtcSyncLog;
use App\Models\User;
use App\Services\Dtc\DtcPosImportService;
use App\Services\Dtc\DtcPriceExcelImportService;
use App\Services\Dtc\DtcPriceListPdfService;
use App\Services\Dtc\DtcPriceSyncService;
use App\Services\Dtc\DtcQuoteImportService;
use App\Services\Dtc\DtcQuoteService;
use App\Services\Team\OrgScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DtcCalltronixController extends Controller
{
    public function __construct(
        private readonly OrgScopeService $scope,
        private readonly DtcQuoteService $quotes,
        private readonly DtcPriceSyncService $prices,
        private readonly DtcPriceExcelImportService $priceExcel,
        private readonly DtcPriceListPdfService $priceListPdf,
        private readonly DtcQuoteImportService $quoteImporter,
        private readonly DtcPosImportService $posImporter,
    ) {}

    public function meta(Request $r): JsonResponse
    {
        $u = $this->actor($r);

        try {
            $stats = $this->buildStats($u, $r->input('date_from'), $r->input('date_to'));
        } catch (Throwable $e) {
            // Price-list schema may lag deploy; never block the whole DTC module.
            $stats = [
                'quotes' => ['count' => 0, 'total_amount' => 0.0],
                'pos_orders' => ['count' => 0, 'total_amount' => 0.0],
                'qt_count' => 0,
                'so_count' => 0,
                'prices' => ['count' => 0, 'matched' => 0],
                'date_from' => $r->input('date_from'),
                'date_to' => $r->input('date_to'),
                'schema_warning' => $e->getMessage(),
            ];
        }

        return response()->json([
            'price_class' => 'DTCACCOUNT',
            'currency' => 'KES',
            'pos_order_customer_id' => DtcQuoteService::POS_ORDER_CUSTOMER_ID,
            'is_admin' => $this->admin($u),
            'can_create' => $this->can($u, 'dtc.quotes.create'),
            'can_convert' => $this->can($u, 'dtc.quotes.convert'),
            'can_import_quotes' => $this->manager($u) && $this->can($u, 'dtc.quotes.create'),
            'can_import_pos' => $this->manager($u) && $this->can($u, 'dtc.quotes.create'),
            // Anyone with DTC access can upload the Sales Prices Excel/CSV.
            'can_import_prices' => true,
            // Acumatica product/price sync: admins + scoped managers (not pure consultants).
            'can_sync_prices' => $this->admin($u) || $this->manager($u),
            'last_product_sync' => DtcSyncLog::where('sync_type', 'price_list_products')->latest('started_at')->first(),
            'last_price_sync' => DtcSyncLog::where('sync_type', 'prices')->latest('started_at')->first(),
            'last_excel_import' => DtcSyncLog::where('sync_type', 'price_excel_import')->latest('started_at')->first(),
            'last_quote_import' => DtcSyncLog::where('sync_type', 'quotes')->latest('started_at')->first(),
            'last_pos_import' => DtcSyncLog::where('sync_type', 'pos_orders')->latest('started_at')->first(),
            'price_filters' => $this->priceFilterOptions(),
            'stats' => $stats,
            'price_list_ready' => $this->dtcPriceListReady(),
        ]);
    }

    public function stats(Request $r): JsonResponse
    {
        $u = $this->actor($r);

        return response()->json($this->buildStats($u, $r->input('date_from'), $r->input('date_to')));
    }
    public function customers(Request $r): JsonResponse
    {
        $u=$this->actor($r);$quotes=$this->visibleQuotes($u)->with(['lines','conversion'])->latest()->get();
        $rows=$quotes->groupBy(function($quote){$d=$quote->conversion?->customer_details_snapshot?:$quote->customer_details_snapshot?:[];return strtolower(trim((string)($d['email']??$d['phone']??$d['name']??$quote->customer_name)));})->map(function($group,$key){$latest=$group->first();$d=$latest->conversion?->customer_details_snapshot?:$latest->customer_details_snapshot?:[];$converted=$group->filter(fn($q)=>$q->conversion?->status==='success');return ['customer_key'=>$key,'acumatica_id'=>$latest->customer_acumatica_id,'accounting_customer_id'=>DtcQuoteService::POS_ORDER_CUSTOMER_ID,'name'=>$d['name']??$latest->customer_name,'email'=>$d['email']??null,'phone'=>$d['phone']??null,'billing_address'=>['address_line1'=>$d['address_line1']??null,'address_line2'=>$d['address_line2']??null],'quote_count'=>$group->count(),'so_count'=>$converted->count(),'last_order_date'=>$converted->max(fn($q)=>$q->conversion?->converted_at?->toDateString()),'quotes'=>$group->map(fn($q)=>['id'=>$q->id,'number'=>$q->acumatica_quote_nbr?:$q->public_ref,'total'=>$q->quoted_total,'status'=>$q->status])->values(),'pos_orders'=>$converted->map(fn($q)=>['quote_id'=>$q->id,'order_nbr'=>$q->conversion->acumatica_order_nbr,'total'=>$q->conversion->order_total,'converted_at'=>$q->conversion->converted_at,'lines'=>$q->conversion->pos_lines_snapshot?:[]])->values()];})->values();
        $masters=AcumaticaCustomer::whereRaw('UPPER(customer_class)=?',['DTCACCOUNT'])->where(fn($x)=>$x->whereNull('status')->orWhereNotIn('status',['Inactive','Deleted']));if(!$this->manager($u))$this->scope->applyCustomerScope($masters,$u);
        foreach($masters->get() as $master){if($rows->contains(fn($x)=>$x['acumatica_id']===$master->acumatica_id))continue;$billing=is_array($master->billing_address)?$master->billing_address:[];$rows->push(['customer_key'=>'master:'.strtolower($master->acumatica_id),'acumatica_id'=>$master->acumatica_id,'accounting_customer_id'=>DtcQuoteService::POS_ORDER_CUSTOMER_ID,'name'=>$master->name,'email'=>$master->email,'phone'=>$master->phone,'billing_address'=>['address_line1'=>$billing['address_line1']??$billing['line1']??null,'address_line2'=>$billing['address_line2']??$billing['line2']??null],'quote_count'=>0,'so_count'=>0,'last_order_date'=>null,'quotes'=>[],'pos_orders'=>[]]);}
        if($r->filled('q')){$s=strtolower(trim($r->input('q')));$rows=$rows->filter(fn($x)=>str_contains(strtolower(implode(' ',[$x['name'],$x['email'],$x['phone'],$x['acumatica_id']])), $s))->values();}
        $per=min(max($r->integer('per_page',50),5),100);$pageNo=max($r->integer('page',1),1);$page=new LengthAwarePaginator($rows->forPage($pageNo,$per)->values(),$rows->count(),$per,$pageNo,['path'=>$r->url(),'query'=>$r->query()]);return response()->json($page);
    }
    public function priceList(Request $r): JsonResponse
    {
        $this->actor($r);

        if (! $this->dtcPriceListReady()) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'total' => 0,
                'message' => 'dtc_price_list table is missing. Run: php artisan migrate',
            ]);
        }

        // Show all synced catalog rows (including zero/null prices).
        $q = DtcPriceList::query()->where('price_code', 'DTCACCOUNT');

        if ($r->boolean('in_catalog_only', false)) {
            $q->where('in_catalog', true);
        }

        if ($r->filled('brand') && Schema::hasColumn('dtc_price_list', 'brand')) {
            $q->where('brand', $r->input('brand'));
        }

        if ($r->filled('taxation') && Schema::hasColumn('dtc_price_list', 'taxation')) {
            $q->where('taxation', $r->input('taxation'));
        }

        if ($r->filled('product_type') && Schema::hasColumn('dtc_price_list', 'product_type')) {
            $q->where('product_type', $r->input('product_type'));
        }

        // Multi-select warehouses: ?warehouses=DTC,FGS or warehouse[]=DTC&warehouse[]=FGS
        $warehouseInput = $r->input('warehouses', $r->input('warehouse'));
        $warehouseIds = [];
        if (is_array($warehouseInput)) {
            $warehouseIds = array_values(array_filter(array_map(
                static fn ($v) => strtoupper(trim((string) $v)),
                $warehouseInput,
            )));
        } elseif (is_string($warehouseInput) && trim($warehouseInput) !== '') {
            $warehouseIds = array_values(array_filter(array_map(
                static fn ($v) => strtoupper(trim($v)),
                explode(',', $warehouseInput),
            )));
        }
        if ($warehouseIds !== []) {
            $q->where(function ($x) use ($warehouseIds) {
                foreach ($warehouseIds as $i => $wh) {
                    $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                    $x->{$method}('UPPER(TRIM(default_warehouse_id)) = ?', [$wh]);
                }
            });
        }

        if ($r->input('stock') === 'in_stock') {
            $q->where('qty_available', '>', 0);
        } elseif ($r->input('stock') === 'out_of_stock') {
            $q->where(function ($x) {
                $x->whereNull('qty_available')->orWhere('qty_available', '<=', 0);
            });
        }

        if ($r->input('priced') === 'yes') {
            $q->where('dtc_price', '>', 0);
        } elseif ($r->input('priced') === 'no') {
            $q->where(function ($x) {
                $x->whereNull('dtc_price')->orWhere('dtc_price', '<=', 0);
            });
        }

        // Search by Inventory ID and product name (description).
        if ($r->filled('q')) {
            $s = trim((string) $r->input('q'));
            $q->where(function ($x) use ($s) {
                $x->where('inventory_id', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
                if (Schema::hasColumn('dtc_price_list', 'brand')) {
                    $x->orWhere('brand', 'like', "%{$s}%");
                }
            });
        }

        $page = $q->orderBy('inventory_id')
            ->paginate(min(max($r->integer('per_page', 100), 5), 500));

        $hasBrand = Schema::hasColumn('dtc_price_list', 'brand');
        $hasTaxation = Schema::hasColumn('dtc_price_list', 'taxation');
        $hasProductType = Schema::hasColumn('dtc_price_list', 'product_type');
        $hasSource = Schema::hasColumn('dtc_price_list', 'source');
        $hasEffective = Schema::hasColumn('dtc_price_list', 'effective_date');

        $page->getCollection()->transform(function (DtcPriceList $row) use ($hasBrand, $hasTaxation, $hasProductType, $hasSource, $hasEffective) {
            $net = $row->netPrice();
            $effective = $row->effectivePrice();
            $vatRate = $row->vatRate();

            return [
                'id' => $row->id,
                'inventory_id' => $row->inventory_id,
                'description' => $row->description,
                'brand' => $hasBrand ? $row->brand : null,
                'product_type' => $hasProductType ? $row->product_type : null,
                'uom' => $row->uom,
                // Sell price used by quotes / UI (incl. 16% VAT when TAXABLE).
                'price' => number_format($effective, 6, '.', ''),
                'price_ex_vat' => number_format($net, 6, '.', ''),
                'vat_rate' => $vatRate,
                'vat_amount' => number_format($row->vatAmount(), 6, '.', ''),
                'is_taxable' => $row->isTaxable(),
                'dtc_price' => $row->dtc_price,
                'default_price' => $row->default_price,
                'break_qty' => $hasEffective ? $row->break_qty : null,
                'currency_id' => $row->currency_id ?: 'KES',
                'price_code' => $row->price_code,
                'price_type' => $hasTaxation ? $row->price_type : null,
                'taxation' => $hasTaxation ? $row->taxation : null,
                'tax' => $hasTaxation ? $row->tax : null,
                'qty_available' => $row->qty_available,
                'qty_on_hand' => $row->qty_on_hand,
                'default_warehouse_id' => $row->default_warehouse_id,
                'item_status' => $row->item_status,
                'in_catalog' => (bool) $row->in_catalog,
                'product_synced_at' => $row->product_synced_at,
                'price_synced_at' => $row->price_synced_at,
                'synced_at' => $row->synced_at,
                'source' => $hasSource ? $row->source : null,
                'effective_date' => ($hasEffective ? $row->effective_date?->toDateString() : null)
                    ?? $row->price_synced_at?->toDateString(),
                'expiration_date' => $hasEffective ? $row->expiration_date?->toDateString() : null,
            ];
        });

        return response()->json($page);
    }

    public function quotes(Request $r): JsonResponse
    {
        $u = $this->actor($r);
        $q = $this->visibleQuotes($u)
            ->with(['lines', 'creator:id,name,email', 'conversion'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if ($r->filled('q')) {
            $s = trim((string) $r->input('q'));
            $q->where(function ($x) use ($s) {
                $x->where('public_ref', 'like', "%{$s}%")
                    ->orWhere('acumatica_quote_nbr', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%");
            });
        }
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        $page = $q->paginate(min(max($r->integer('per_page', 50), 5), 100));
        $page->getCollection()->transform(function (DtcQuote $quote) {
            $arr = $quote->toArray();
            // Acumatica QT Date (stored on import/submit); fall back to local created_at.
            $arr['acumatica_date'] = $quote->submitted_at?->toDateString()
                ?? $quote->created_at?->toDateString();

            return $arr;
        });

        return response()->json($page);
    }
    public function show(Request $r,DtcQuote $quote): JsonResponse { $u=$this->actor($r);$this->visibleQuote($u,$quote);return response()->json($quote->load(['lines','creator:id,name,email','conversion'])); }
    public function store(Request $r): JsonResponse { $u=$this->actor($r,'dtc.quotes.create');$data=$this->validateQuote($r);$this->customerAllowed($u,$data['customer_acumatica_id']);return response()->json($this->quotes->saveDraft($data,$u),201); }
    public function update(Request $r,DtcQuote $quote): JsonResponse { $u=$this->actor($r,'dtc.quotes.create');$this->editQuote($u,$quote);$data=$this->validateQuote($r);$this->customerAllowed($u,$data['customer_acumatica_id']);return response()->json($this->quotes->saveDraft($data,$u,$quote)); }
    public function submit(Request $r,DtcQuote $quote): JsonResponse { $u=$this->actor($r,'dtc.quotes.create');$this->editQuote($u,$quote);return response()->json($this->quotes->submit($quote)); }
    public function convert(Request $r,DtcQuote $quote): JsonResponse { $u=$this->actor($r,'dtc.quotes.convert');$this->visibleQuote($u,$quote);if((int)$quote->created_by!==$u->id&&!$this->manager($u))abort(403,'Only the creator or scoped management can convert this quote.');return response()->json($this->quotes->convert($quote,$u)); }
    public function updateConvertedCustomer(Request $r,DtcQuote $quote): JsonResponse { $u=$this->actor($r,'dtc.quotes.convert');$this->visibleQuote($u,$quote);if((int)$quote->created_by!==$u->id&&!$this->manager($u))abort(403);$data=$r->validate(['name'=>['required','string','max:255'],'email'=>['nullable','email','max:255'],'phone'=>['nullable','string','max:50'],'address_line1'=>['nullable','string','max:255'],'address_line2'=>['nullable','string','max:255']]);return response()->json($this->quotes->updateConvertedCustomer($quote,$data)); }
    public function salesOrders(Request $r): JsonResponse
    {
        $u = $this->actor($r);
        $customers = AcumaticaCustomer::select('acumatica_id')->where(function ($x) {
            $x->whereRaw('UPPER(customer_class)=?', ['DTCACCOUNT'])
                ->orWhere('acumatica_id', DtcQuoteService::POS_ORDER_CUSTOMER_ID);
        });
        if (! $this->manager($u)) {
            $this->scope->applyCustomerScope($customers, $u);
        }

        $q = AcumaticaSalesOrder::with('lines')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $customers)
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if ($r->filled('q')) {
            $s = trim((string) $r->input('q'));
            $q->where(function ($x) use ($s) {
                $x->where('acumatica_order_nbr', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%");
            });
        }

        $page = $q->paginate(min(max($r->integer('per_page', 50), 5), 100));
        $links = \App\Models\DtcQuoteConversion::whereIn(
            'acumatica_order_nbr',
            collect($page->items())->pluck('acumatica_order_nbr'),
        )->with('quote:id,public_ref,acumatica_quote_nbr')->get()->keyBy('acumatica_order_nbr');

        $page->setCollection(collect($page->items())->map(function ($o) use ($links) {
            $o->setAttribute('dtc_conversion', $links->get($o->acumatica_order_nbr));
            // Acumatica SO Date field (order_date from ERP import).
            $o->setAttribute(
                'acumatica_date',
                $o->order_date
                    ? (\Illuminate\Support\Carbon::parse($o->order_date)->toDateString())
                    : null,
            );

            return $o;
        }));

        return response()->json($page);
    }
    /** Step 1 — sync products from local inventory into dtc_price_list (plugin stockItem import). */
    public function syncProducts(Request $r): JsonResponse
    {
        $u = $this->actor($r);
        if (! $this->admin($u) && ! $this->manager($u)) {
            abort(403, 'Only administrators or managers can sync DTC products from inventory.');
        }

        return response()->json($this->prices->syncProducts($u));
    }

    /**
     * Step 2 — PUT SalesPricesInquiry, filter DTCACCOUNT, match InventoryID → dtc_price.
     * Also accepts full sync (products + prices) when ?full=1.
     */
    public function refreshPrices(Request $r): JsonResponse
    {
        $u = $this->actor($r);
        if (! $this->admin($u) && ! $this->manager($u)) {
            abort(403, 'Only administrators or managers can import DTCACCOUNT prices from Acumatica.');
        }

        if ($r->boolean('full')) {
            return response()->json($this->prices->sync($u));
        }

        return response()->json($this->prices->syncPrices($u));
    }

    /**
     * Import DTC Sales Prices Excel/CSV (Inventory ID match).
     * Accepts the Acumatica export: Price Type, Price Code, Inventory ID, … Tax Category, Price.
     *
     * Runs inline (not a named queue) so uploads work on hosts that only run
     * `queue:work` without `--queue=imports`. File is still stored for audit/retry.
     */
    public function importPricesExcel(Request $r): JsonResponse
    {
        // Any authenticated DTC user (dtc.view) can import the price list Excel/CSV.
        $u = $this->actor($r);

        // Prefer extension checks over MIME — Windows/Chrome often send application/octet-stream for xlsx.
        $r->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $r->file('file');
        if (! $file) {
            return response()->json(['message' => 'No file uploaded.'], 422);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return response()->json([
                'message' => 'Upload an Excel (.xlsx/.xls) or CSV (.csv) file. Got: '.($ext !== '' ? $ext : 'unknown type'),
            ], 422);
        }

        // Abandon stuck jobs so a dead queue worker cannot block all future uploads.
        DtcSyncLog::query()
            ->where('sync_type', 'price_excel_import')
            ->whereIn('status', ['queued', 'running'])
            ->where(function ($q) {
                $q->whereNull('started_at')
                    ->orWhere('started_at', '<', now()->subMinutes(20));
            })
            ->update([
                'status' => 'failed',
                'error_message' => 'Import timed out or was abandoned (no worker completed it).',
                'finished_at' => now(),
            ]);

        if (DtcSyncLog::where('sync_type', 'price_excel_import')->whereIn('status', ['queued', 'running'])->exists()) {
            return response()->json([
                'message' => 'Another price-list import is already running. Wait a minute and try again.',
            ], 409);
        }

        $queueJobId = (string) Str::uuid();
        $storedPath = $file->storeAs(
            'dtc-price-imports',
            $queueJobId.'.'.$ext,
            'local',
        );
        $log = DtcSyncLog::create([
            'sync_type' => 'price_excel_import',
            'status' => 'running',
            'storage_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'queue_job_id' => $queueJobId,
            'triggered_by' => $u->id,
            'started_at' => now(),
            'progress' => ['rows_read' => 0, 'processed' => 0],
            'metadata' => ['filename' => $file->getClientOriginalName(), 'size' => $file->getSize()],
        ]);

        try {
            // Prefer the stored copy (local disk root = storage/app/private).
            if (Storage::disk('local')->exists($storedPath)) {
                $uploaded = new UploadedFile(
                    Storage::disk('local')->path($storedPath),
                    (string) $file->getClientOriginalName(),
                    $file->getMimeType() ?: null,
                    null,
                    true,
                );
            } else {
                $uploaded = $file;
            }

            $result = $this->priceExcel->import($uploaded, $u, $log);
            $fresh = $log->fresh()?->load('triggeredBy:id,name');

            return response()->json([
                'job_id' => $log->id,
                'status' => $fresh?->status ?? 'completed',
                'data' => $fresh,
                'ok' => $result['ok'] ?? true,
                'rows_read' => $result['rows_read'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'created' => $result['created'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'unmatched' => $result['unmatched'] ?? 0,
                'errors' => $result['errors'] ?? [],
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return response()->json([
                'job_id' => $log->id,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'data' => $log->fresh(),
            ], 422);
        }
    }

    public function priceImportJobs(Request $r): JsonResponse
    {
        $this->actor($r);
        return response()->json(DtcSyncLog::query()->where('sync_type', 'price_excel_import')->with('triggeredBy:id,name')->latest('id')->paginate(20));
    }

    public function priceImportJob(Request $r, DtcSyncLog $syncLog): JsonResponse
    {
        $this->actor($r);
        abort_unless($syncLog->sync_type === 'price_excel_import', 404);
        return response()->json($syncLog->load('triggeredBy:id,name'));
    }

    public function exportPriceListPdf(Request $r): Response
    {
        $this->actor($r);
        $rows = $this->filteredPriceListQuery($r)->orderBy('inventory_id')->limit(10000)->get();
        $pdf = $this->priceListPdf->render($rows, $r->only(['q', 'brand', 'taxation', 'product_type', 'warehouses', 'warehouse', 'stock', 'priced']));
        $filename = 'Kim-Fay-DTC-Price-List-'.now('Africa/Nairobi')->toDateString().'.pdf';
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    public function importQuotes(Request $r): JsonResponse
    {
        $u = $this->actor($r, 'dtc.quotes.create');
        if (! $this->manager($u)) {
            abort(403, 'Only scoped management or administrators can import Acumatica quotes.');
        }

        $data = $r->validate([
            'scope' => ['required', 'in:range,date,all'],
            'date' => ['nullable', 'required_if:scope,date', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'required_if:scope,range', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_if:scope,range', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $from = null;
        $to = null;
        if ($data['scope'] === 'range') {
            $from = $data['date_from'];
            $to = $data['date_to'];
        } elseif ($data['scope'] === 'date') {
            $from = $data['date'];
            $to = $data['date'];
        }

        return response()->json($this->quoteImporter->import($from, $to, $u));
    }

    public function importPosOrders(Request $r): JsonResponse
    {
        $u = $this->actor($r, 'dtc.quotes.create');
        if (! $this->manager($u)) {
            abort(403, 'Only scoped management or administrators can import POS orders.');
        }

        $data = $r->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->posImporter->import($data['date_from'], $data['date_to'], $u));
    }

    /**
     * @return array{quotes:array{count:int,total_amount:float},pos_orders:array{count:int,total_amount:float},prices:array{count:int,matched:int}}
     */
    private function buildStats(User $u, mixed $dateFrom = null, mixed $dateTo = null): array
    {
        $from = is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
        $to = is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

        $quotes = $this->visibleQuotes($u);
        if ($from || $to) {
            $quotes->where(function ($q) use ($from, $to) {
                $q->where(function ($inner) use ($from, $to) {
                    if ($from) {
                        $inner->whereDate('submitted_at', '>=', $from);
                    }
                    if ($to) {
                        $inner->whereDate('submitted_at', '<=', $to);
                    }
                })->orWhere(function ($inner) use ($from, $to) {
                    $inner->whereNull('submitted_at');
                    if ($from) {
                        $inner->whereDate('created_at', '>=', $from);
                    }
                    if ($to) {
                        $inner->whereDate('created_at', '<=', $to);
                    }
                });
            });
        }

        $quoteCount = (clone $quotes)->count();
        $quoteTotal = (float) (clone $quotes)->sum('quoted_total');

        $customers = AcumaticaCustomer::select('acumatica_id')->where(function ($x) {
            $x->whereRaw('UPPER(customer_class)=?', ['DTCACCOUNT'])
                ->orWhere('acumatica_id', DtcQuoteService::POS_ORDER_CUSTOMER_ID);
        });
        if (! $this->manager($u)) {
            $this->scope->applyCustomerScope($customers, $u);
        }

        $pos = AcumaticaSalesOrder::query()
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $customers);
        if ($from) {
            $pos->whereDate('order_date', '>=', $from);
        }
        if ($to) {
            $pos->whereDate('order_date', '<=', $to);
        }

        $priceCount = 0;
        $matched = 0;
        if ($this->dtcPriceListReady()) {
            try {
                $catalog = DtcPriceList::query()->where('price_code', 'DTCACCOUNT');
                $priceCount = (clone $catalog)->count();
                $matched = (clone $catalog)->where('in_catalog', true)->count();
            } catch (Throwable) {
                $priceCount = 0;
                $matched = 0;
            }
        }

        $posCount = (clone $pos)->count();
        $posTotal = round((float) (clone $pos)->sum('order_total'), 2);

        return [
            'quotes' => [
                'count' => $quoteCount,
                'total_amount' => round($quoteTotal, 2),
            ],
            'pos_orders' => [
                'count' => $posCount,
                'total_amount' => $posTotal,
            ],
            // Aliases for UI stat cards that emphasise order counts.
            'qt_count' => $quoteCount,
            'so_count' => $posCount,
            'prices' => [
                'count' => $priceCount,
                'matched' => $matched,
            ],
            'date_from' => $from,
            'date_to' => $to,
        ];
    }

    /**
     * @return array{brands:list<string>,taxations:list<string>,product_types:list<string>,warehouses:list<string>}
     */
    private function priceFilterOptions(): array
    {
        $empty = [
            'brands' => [],
            'taxations' => [],
            'product_types' => [],
            'warehouses' => [],
        ];

        if (! $this->dtcPriceListReady()) {
            return $empty;
        }

        try {
            $base = DtcPriceList::query()->where('price_code', 'DTCACCOUNT');
            $hasBrand = Schema::hasColumn('dtc_price_list', 'brand');
            $hasTaxation = Schema::hasColumn('dtc_price_list', 'taxation');
            $hasProductType = Schema::hasColumn('dtc_price_list', 'product_type');

            return [
                'brands' => $hasBrand
                    ? (clone $base)->whereNotNull('brand')->where('brand', '!=', '')
                        ->distinct()->orderBy('brand')->pluck('brand')->values()->all()
                    : [],
                'taxations' => $hasTaxation
                    ? (clone $base)->whereNotNull('taxation')->where('taxation', '!=', '')
                        ->distinct()->orderBy('taxation')->pluck('taxation')->values()->all()
                    : [],
                'product_types' => $hasProductType
                    ? (clone $base)->whereNotNull('product_type')->where('product_type', '!=', '')
                        ->distinct()->orderBy('product_type')->pluck('product_type')->values()->all()
                    : [],
                // UI only exposes DTC + FGS (other warehouses hidden).
                'warehouses' => ['DTC', 'FGS'],
            ];
        } catch (Throwable) {
            return [
                ...$empty,
                'warehouses' => ['DTC', 'FGS'],
            ];
        }
    }

    private function filteredPriceListQuery(Request $r): Builder
    {
        $q = DtcPriceList::query()->where('price_code', 'DTCACCOUNT');
        if ($r->boolean('in_catalog_only', false)) $q->where('in_catalog', true);
        foreach (['brand', 'taxation', 'product_type'] as $field) {
            if ($r->filled($field) && Schema::hasColumn('dtc_price_list', $field)) $q->where($field, $r->input($field));
        }
        $warehouseInput = $r->input('warehouses', $r->input('warehouse'));
        $warehouseIds = is_array($warehouseInput)
            ? array_values(array_filter(array_map(fn ($v) => strtoupper(trim((string) $v)), $warehouseInput)))
            : array_values(array_filter(array_map(fn ($v) => strtoupper(trim($v)), explode(',', (string) $warehouseInput))));
        if ($warehouseIds !== []) $q->whereIn(DB::raw('UPPER(TRIM(default_warehouse_id))'), $warehouseIds);
        if ($r->input('stock') === 'in_stock') $q->where('qty_available', '>', 0);
        if ($r->input('stock') === 'out_of_stock') $q->where(fn ($x) => $x->whereNull('qty_available')->orWhere('qty_available', '<=', 0));
        if ($r->input('priced') === 'yes') $q->where('dtc_price', '>', 0);
        if ($r->input('priced') === 'no') $q->where(fn ($x) => $x->whereNull('dtc_price')->orWhere('dtc_price', '<=', 0));
        if ($r->filled('q')) {
            $s = trim((string) $r->input('q'));
            $q->where(function ($x) use ($s) {
                $x->where('inventory_id', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%");
                if (Schema::hasColumn('dtc_price_list', 'brand')) $x->orWhere('brand', 'like', "%{$s}%");
            });
        }
        return $q;
    }

    /** True when dtc_price_list exists (core table for DTC prices). */
    private function dtcPriceListReady(): bool
    {
        try {
            return Schema::hasTable('dtc_price_list');
        } catch (Throwable) {
            return false;
        }
    }
    private function validateQuote(Request $r): array { return $r->validate(['customer_acumatica_id'=>['required','string','max:50'],'description'=>['nullable','string','max:255'],'lines'=>['required','array','min:1','max:100'],'lines.*.inventory_id'=>['required','string','max:80'],'lines.*.quantity'=>['required','numeric','gt:0','max:100000']]); }
    private function visibleQuotes(User $u): Builder { $customers=AcumaticaCustomer::select('acumatica_id')->where(function($x){$x->whereRaw('UPPER(customer_class)=?',['DTCACCOUNT'])->orWhere('acumatica_id',DtcQuoteService::POS_ORDER_CUSTOMER_ID);});if(!$this->manager($u))$this->scope->applyCustomerScope($customers,$u);return DtcQuote::whereIn('customer_acumatica_id',$customers); }
    private function visibleQuote(User $u,DtcQuote $q): void { if(!$this->visibleQuotes($u)->whereKey($q->id)->exists())abort(403); }
    private function editQuote(User $u,DtcQuote $q): void { $this->visibleQuote($u,$q);if((int)$q->created_by!==$u->id&&!$this->manager($u))abort(403); }
    private function customerAllowed(User $u,string $id): void { $c=AcumaticaCustomer::where('acumatica_id',$id)->whereRaw('UPPER(customer_class)=?',['DTCACCOUNT'])->exists();if(!$c||!$this->scope->customerAccessible($u,$id))abort(403,'DTC customer is outside your scope.'); }
    private function actor(Request $r,?string $permission='dtc.view'): User { $u=$r->user();if(!$u)abort(401);if(!$this->admin($u)&&!$this->can($u,$permission))abort(403,'You do not have access to DTC/DTB Calltronix.');return $u; }
    private function can(User $u,?string $p): bool { return $p===null||$u->hasPermission($p); }
    private function admin(User $u): bool { return $u->role==='Administrator'||(bool)$u->is_super_admin; }
    private function manager(User $u): bool { return $this->admin($u)||in_array((string)$u->org_level,['hod','executive','c_suite'],true)||in_array($u->role,['Executive','HOD','Sales Operations','Customer Service Manager'],true); }
}
