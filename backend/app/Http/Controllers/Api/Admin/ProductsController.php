<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportProductsCsvJob;
use App\Models\Product;
use App\Models\ProductImportLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Production\ProductionSummaryService;

class ProductsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with([
            'brand:id,name,ownership',
            'category:id,name',
            'subCategory:id,name',
            'tradingGroup:id,name',
        ])->orderBy('inventory_id');
        if ($q = trim((string) $request->input('q'))) {
            $query->where(fn ($x) => $x->where('inventory_id', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }
        foreach (['brand_id', 'category_id', 'trading_group_id', 'ownership'] as $field) {
            if ($value = $request->input($field)) $query->where($field, $value);
        }
        if ($request->has('is_active')) $query->where('is_active', $request->boolean('is_active'));
        return response()->json($query->paginate(min(200, max(1, $request->integer('per_page', 50)))));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'], 'brand_id' => ['nullable', 'exists:brands,id'],
            'category_id' => ['nullable', 'exists:categories,id'], 'sub_category_id' => ['nullable', 'exists:categories,id'],
            'trading_group_id' => ['nullable', 'exists:trading_groups,id'], 'portfolio_group' => ['nullable', 'string', 'max:150'],
            'ownership' => ['nullable', Rule::in(['manufactured', 'partner'])],
            'conversion_factor' => ['nullable', 'numeric', 'min:0'], 'uom' => ['nullable', 'string', 'max:100'],
            'profit_margin_target' => ['nullable', 'numeric', 'min:0', 'max:1'], 'supplier' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $product->update($data + ['import_locked' => true, 'manually_edited_at' => now(),
            'updated_by' => $request->user()?->id]);
        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);
        return response()->json($product->fresh()->load(['brand', 'category', 'subCategory', 'tradingGroup']));
    }

    public function unlock(Request $request, Product $product): JsonResponse
    {
        $product->update(['import_locked' => false, 'updated_by' => $request->user()?->id]);
        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);
        return response()->json(['message' => 'Product unlocked for future imports.', 'product' => $product->fresh()]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480']]);
        $file = $data['file'];
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('product-imports', $name, 'local');
        $log = ProductImportLog::create(['status' => 'queued', 'file_name' => $file->getClientOriginalName(),
            'file_path' => $path, 'triggered_by_user_id' => $request->user()?->id]);
        ImportProductsCsvJob::dispatch($log->id)->afterResponse();
        return response()->json(['message' => 'Product import queued.', 'import' => $log], 202);
    }

    public function imports(): JsonResponse
    {
        return response()->json(['data' => ProductImportLog::query()->latest()->limit(30)->get()]);
    }

    public function import(ProductImportLog $productImportLog): JsonResponse
    {
        return response()->json(['import' => $productImportLog->fresh()]);
    }

    public function errors(ProductImportLog $productImportLog): StreamedResponse
    {
        return response()->streamDownload(function () use ($productImportLog): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Row', 'Inventory ID', 'Type', 'Message']);
            foreach ($productImportLog->errors ?? [] as $error) fputcsv($out, [
                $error['row'] ?? '', $error['inventory_id'] ?? '', $error['type'] ?? '', $error['message'] ?? '',
            ]);
            fclose($out);
        }, "product-import-{$productImportLog->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }
}
