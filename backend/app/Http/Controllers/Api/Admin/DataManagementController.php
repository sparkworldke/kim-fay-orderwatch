<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataManagementController extends Controller
{
    private const DATASETS = ['all', 'fill_rate', 'backorders', 'consultants'];

    private const EXPORT_HEADERS = [
        'dataset',
        'inventory_id',
        'item_description',
        'item_class',
        'warehouse_id',
        'qty_on_hand',
        'qty_available',
        'ordered_qty',
        'shipped_qty',
        'open_qty',
        'fill_rate_pct',
        'fill_rate_status',
        'order_nbr',
        'customer_id',
        'customer_name',
        'order_status',
        'order_date',
        'requested_on',
        'scheduled_shipment_date',
        'backorder_qty',
        'revenue_at_risk',
        'fulfillment_status',
        'reason_code',
        'reason_notes',
        'rep_code',
        'sales_consultant_name',
        'consultant_email',
        'assigned_orders',
        'active_orders',
        'completed_orders',
        'assigned_revenue',
        'last_order_date',
        'synced_at',
    ];

    private const IMPORT_COLUMNS = [
        'order_nbr',
        'rep_code',
        'customer_id',
        'order_date',
        'order_total',
    ];

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $dataset = strtolower(trim((string) $request->query('dataset', 'all')));

        if (! in_array($dataset, self::DATASETS, true)) {
            return response()->json([
                'message' => 'Unsupported export dataset.',
                'errors' => ['dataset' => ['Choose all, fill_rate, backorders, or consultants.']],
            ], 422);
        }

        $filename = 'orderwatch-'.$dataset.'-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($dataset): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel (which doesn't sniff encoding) renders special
            // characters in customer/consultant names correctly instead of mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::EXPORT_HEADERS);

            if ($dataset === 'all' || $dataset === 'fill_rate') {
                foreach ($this->fillRateRows() as $row) {
                    fputcsv($out, $this->normalizeExportRow('fill_rate', $row));
                }
            }

            if ($dataset === 'all' || $dataset === 'backorders') {
                foreach ($this->backorderRows() as $row) {
                    fputcsv($out, $this->normalizeExportRow('backorders', $row));
                }
            }

            if ($dataset === 'all' || $dataset === 'consultants') {
                foreach ($this->consultantRows() as $row) {
                    fputcsv($out, $this->normalizeExportRow('consultants', $row));
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function importSalesOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'rep_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 ._\\-\\/]+$/'],
        ]);

        $consultantsByCode = User::query()
            ->where('role', 'Sales Consultant')
            ->where('is_active', true)
            ->whereNotNull('rep_code')
            ->get(['name', 'email', 'rep_code'])
            ->keyBy(fn (User $user) => strtoupper((string) $user->rep_code));

        // Scoping the import to one consultant (Sync Operations panel) means every
        // row must belong to them — the CSV's own rep_code column becomes optional.
        $scopedRepCode = null;
        if (! empty($validated['rep_code'])) {
            $scopedRepCode = strtoupper(trim($validated['rep_code']));

            if (! $consultantsByCode->has($scopedRepCode)) {
                return response()->json([
                    'message' => "Rep Code {$scopedRepCode} is not assigned to an active Sales Consultant user.",
                    'errors' => ['rep_code' => ["Rep Code {$scopedRepCode} is not assigned to an active Sales Consultant user."]],
                ], 422);
            }
        }

        $path = $validated['file']->getRealPath();
        if (! is_string($path) || ! is_readable($path)) {
            return response()->json(['message' => 'Uploaded CSV could not be read.'], 422);
        }

        [$headers, $rows] = $this->readCsv($path);
        $requiredColumns = $scopedRepCode !== null
            ? array_values(array_diff(self::IMPORT_COLUMNS, ['rep_code']))
            : self::IMPORT_COLUMNS;
        $missing = array_values(array_diff($requiredColumns, $headers));

        if ($missing !== []) {
            return response()->json([
                'message' => 'CSV is missing required columns: '.implode(', ', $missing),
                'errors' => ['file' => ['Missing required columns: '.implode(', ', $missing)]],
            ], 422);
        }

        $errors = [];
        $validRows = [];

        foreach ($rows as $rowNumber => $row) {
            $rowRepCode = strtoupper(trim((string) ($row['rep_code'] ?? '')));

            if ($scopedRepCode !== null && $rowRepCode !== '' && $rowRepCode !== $scopedRepCode) {
                $errors[] = [
                    'row' => $rowNumber,
                    'order_nbr' => $row['order_nbr'] ?? null,
                    'errors' => ["Row's Rep Code {$rowRepCode} does not match the selected consultant {$scopedRepCode}."],
                ];
                continue;
            }

            $row['rep_code'] = $scopedRepCode !== null && $rowRepCode === '' ? $scopedRepCode : $rowRepCode;
            $row['order_nbr'] = strtoupper(trim((string) ($row['order_nbr'] ?? '')));
            $row['order_type'] = strtoupper(trim((string) ($row['order_type'] ?? 'SO')));

            $validator = Validator::make($row, [
                'order_nbr' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9._\\-\\/]+$/'],
                'rep_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9 ._\\-\\/]+$/'],
                'customer_id' => ['required', 'string', 'max:50'],
                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_order' => ['nullable', 'string', 'max:100'],
                'order_type' => ['nullable', 'string', 'max:10'],
                'status' => ['nullable', 'string', 'max:50'],
                'order_date' => ['required', 'date'],
                'ship_date' => ['nullable', 'date'],
                'requested_on' => ['nullable', 'date'],
                'order_total' => ['required', 'numeric', 'min:0'],
                'currency_id' => ['nullable', 'string', 'max:10'],
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $rowNumber,
                    'order_nbr' => $row['order_nbr'] ?? null,
                    'errors' => $validator->errors()->all(),
                ];
                continue;
            }

            if (! $consultantsByCode->has($row['rep_code'])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'order_nbr' => $row['order_nbr'],
                    'errors' => ["Rep Code {$row['rep_code']} is not assigned to an active Sales Consultant user."],
                ];
                continue;
            }

            $validRows[$rowNumber] = $row;
        }

        if ($errors !== []) {
            return response()->json([
                'message' => 'Sales order import validation failed. No rows were imported.',
                'rep_code' => $scopedRepCode,
                'imported' => 0,
                'failed' => count($errors),
                'errors' => $errors,
            ], 422);
        }

        $imported = 0;

        DB::transaction(function () use ($validRows, $consultantsByCode, &$imported): void {
            foreach ($validRows as $row) {
                $consultant = $consultantsByCode->get($row['rep_code']);

                AcumaticaSalesOrder::updateOrCreate(
                    ['acumatica_order_nbr' => $row['order_nbr']],
                    [
                        'order_type' => AcumaticaSalesOrder::inferOrderType($row['order_nbr'], $row['order_type'] ?? 'SO'),
                        'customer_acumatica_id' => trim((string) $row['customer_id']),
                        'customer_name' => $this->nullable($row['customer_name'] ?? null),
                        'customer_order' => $this->nullable($row['customer_order'] ?? null),
                        'status' => $this->nullable($row['status'] ?? null) ?? 'Imported',
                        'order_date' => $row['order_date'],
                        'ship_date' => $this->nullable($row['ship_date'] ?? null),
                        'requested_on' => $this->nullable($row['requested_on'] ?? null),
                        'order_total' => (float) $row['order_total'],
                        'currency_id' => $this->nullable($row['currency_id'] ?? null) ?? 'KES',
                        'sales_consultant_rep_code' => $row['rep_code'],
                        'sales_consultant_name' => $consultant?->name,
                        'import_source' => 'admin_csv',
                        'raw_payload' => [
                            'source' => 'admin_csv',
                            'imported_at' => now()->toIso8601String(),
                            'row' => $row,
                        ],
                        'synced_at' => now(),
                    ],
                );

                $imported++;
            }
        });

        $consultantLabel = $scopedRepCode !== null
            ? ' for '.($consultantsByCode->get($scopedRepCode)?->name ?? $scopedRepCode)." ({$scopedRepCode})"
            : '';

        return response()->json([
            'message' => "Sales order import complete{$consultantLabel}: {$imported} row(s) imported.",
            'rep_code' => $scopedRepCode,
            'imported' => $imported,
            'failed' => 0,
            'errors' => [],
        ]);
    }

    private function fillRateRows(): iterable
    {
        return DB::table('acumatica_inventory_items as ai')
            ->leftJoin('acumatica_sales_order_lines as sol', 'sol.inventory_id', '=', 'ai.inventory_id')
            ->select([
                'ai.inventory_id',
                'ai.description as item_description',
                'ai.item_class',
                'ai.default_warehouse_id as warehouse_id',
                'ai.qty_on_hand',
                'ai.qty_available',
                DB::raw('COALESCE(SUM(sol.order_qty), 0) as ordered_qty'),
                DB::raw('COALESCE(SUM(sol.shipped_qty), 0) as shipped_qty'),
                DB::raw('COALESCE(SUM(sol.open_qty), 0) as open_qty'),
                DB::raw('CASE WHEN COALESCE(SUM(sol.order_qty), 0) > 0 THEN ROUND((COALESCE(SUM(sol.shipped_qty), 0) / SUM(sol.order_qty)) * 100, 2) ELSE NULL END as fill_rate_pct'),
                DB::raw("CASE WHEN COALESCE(SUM(sol.order_qty), 0) = 0 THEN 'na' WHEN (COALESCE(SUM(sol.shipped_qty), 0) / SUM(sol.order_qty)) >= 0.98 THEN 'good' WHEN (COALESCE(SUM(sol.shipped_qty), 0) / SUM(sol.order_qty)) >= 0.90 THEN 'watch' ELSE 'poor' END as fill_rate_status"),
                'ai.synced_at',
            ])
            ->groupBy('ai.id', 'ai.inventory_id', 'ai.description', 'ai.item_class', 'ai.default_warehouse_id', 'ai.qty_on_hand', 'ai.qty_available', 'ai.synced_at')
            ->orderBy('ai.inventory_id')
            ->cursor();
    }

    private function backorderRows(): iterable
    {
        return AcumaticaBackorderLine::query()
            ->orderByDesc('revenue_at_risk')
            ->cursor();
    }

    private function consultantRows(): iterable
    {
        return User::query()
            ->where('role', 'Sales Consultant')
            ->leftJoin('acumatica_sales_orders as so', 'users.rep_code', '=', 'so.sales_consultant_rep_code')
            ->select([
                'users.name as sales_consultant_name',
                'users.email as consultant_email',
                'users.rep_code',
                DB::raw('COUNT(so.id) as assigned_orders'),
                DB::raw("SUM(CASE WHEN so.id IS NOT NULL AND so.completed_at IS NULL THEN 1 ELSE 0 END) as active_orders"),
                DB::raw("SUM(CASE WHEN so.id IS NOT NULL AND so.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_orders"),
                DB::raw('COALESCE(SUM(so.order_total), 0) as assigned_revenue'),
                DB::raw('MAX(so.order_date) as last_order_date'),
            ])
            ->groupBy('users.id', 'users.name', 'users.email', 'users.rep_code')
            ->orderBy('users.rep_code')
            ->cursor();
    }

    /** @param object|array<string, mixed> $row */
    private function normalizeExportRow(string $dataset, object|array $row): array
    {
        $row = $row instanceof Model ? $row->getAttributes() : (array) $row;

        return array_map(
            fn (string $key) => $key === 'dataset' ? $dataset : $this->cleanCsvValue($row[$key] ?? ''),
            self::EXPORT_HEADERS,
        );
    }

    private function cleanCsvValue(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value) ?? '';
    }

    /** @return array{0: list<string>, 1: array<int, array<string, string>>} */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [[], []];
        }

        $rawHeaders = fgetcsv($handle);
        if (! is_array($rawHeaders)) {
            fclose($handle);
            return [[], []];
        }

        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $rawHeaders);
        $rows = [];
        $rowNumber = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($values === [null] || $values === false) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }

            if (implode('', $row) === '') {
                continue;
            }

            $rows[$rowNumber] = $row;
        }

        fclose($handle);

        return [$headers, $rows];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
