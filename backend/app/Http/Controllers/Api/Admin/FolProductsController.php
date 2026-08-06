<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaInventoryItem;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manage FOL-eligible inventory (single toggle + bulk CSV upload).
 *
 * CSV columns (header optional):
 *   inventory_id, is_fol_eligible (1/0/yes/no), fol_category (optional)
 */
class FolProductsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'eligible_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $query = AcumaticaInventoryItem::query()->orderBy('inventory_id');

        if ($request->boolean('eligible_only')) {
            $query->where('is_fol_eligible', true);
        }

        if ($q !== '') {
            $query->where(function ($scoped) use ($q) {
                $scoped->where('inventory_id', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = $query->paginate($perPage);

        return response()->json($page);
    }

    public function update(Request $request, string $inventoryId): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $validated = $request->validate([
            'is_fol_eligible' => ['required', 'boolean'],
            'fol_category' => ['nullable', Rule::in(['fol_item', 'consumable', 'both'])],
        ]);

        $item = AcumaticaInventoryItem::query()->where('inventory_id', $inventoryId)->first();
        if (! $item) {
            throw ValidationException::withMessages([
                'inventory_id' => ["Inventory item {$inventoryId} was not found. Sync inventory from Acumatica first."],
            ]);
        }

        $item->forceFill([
            'is_fol_eligible' => $validated['is_fol_eligible'],
            'fol_category' => $validated['fol_category'] ?? $item->fol_category,
        ])->save();

        $this->audit->log('fol_product_updated', 'inventory', $inventoryId, [
            'is_fol_eligible' => $item->is_fol_eligible,
            'fol_category' => $item->fol_category,
        ], $request->user()?->id, $request->ip());

        return response()->json($item);
    }

    public function bulkUpload(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'default_eligible' => ['sometimes', 'boolean'],
        ]);

        $defaultEligible = $request->boolean('default_eligible', true);
        $path = $validated['file']->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages(['file' => ['Unable to read uploaded file.']]);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['Unable to open uploaded file.']]);
        }

        $header = null;
        $rowNum = 0;
        $updated = 0;
        $createdSkipped = 0;
        $missing = [];
        $errors = [];

        try {
            while (($raw = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($raw === [null] || $raw === false) {
                    continue;
                }
                $cells = array_map(fn ($c) => trim((string) $c), $raw);
                if ($cells === [] || implode('', $cells) === '') {
                    continue;
                }

                // Detect header
                if ($header === null && $this->looksLikeHeader($cells)) {
                    $header = array_map(fn ($c) => strtolower($c), $cells);
                    continue;
                }

                $parsed = $this->parseRow($cells, $header, $defaultEligible);
                if ($parsed === null) {
                    $errors[] = "Row {$rowNum}: could not parse inventory_id.";
                    continue;
                }
                if ($parsed['fol_category'] !== null && ! in_array($parsed['fol_category'], ['fol_item', 'consumable', 'both'], true)) {
                    $errors[] = "Row {$rowNum}: fol_category must be fol_item, consumable, or both.";
                    continue;
                }

                $item = AcumaticaInventoryItem::query()
                    ->where('inventory_id', $parsed['inventory_id'])
                    ->first();

                if (! $item) {
                    $createdSkipped++;
                    if (count($missing) < 25) {
                        $missing[] = $parsed['inventory_id'];
                    }
                    continue;
                }

                $item->forceFill([
                    'is_fol_eligible' => $parsed['is_fol_eligible'],
                    'fol_category' => $parsed['fol_category'] ?? $item->fol_category,
                ])->save();
                $updated++;
            }
        } finally {
            fclose($handle);
        }

        $this->audit->log('fol_products_bulk_upload', 'inventory', 'bulk', [
            'updated' => $updated,
            'missing' => count($missing),
            'errors' => count($errors),
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'message' => "Updated {$updated} FOL product(s).",
            'updated' => $updated,
            'not_found_count' => $createdSkipped,
            'not_found_sample' => $missing,
            'parse_errors' => $errors,
        ]);
    }

    /** @param list<string> $cells */
    private function looksLikeHeader(array $cells): bool
    {
        $joined = strtolower(implode('|', $cells));

        return str_contains($joined, 'inventory')
            || str_contains($joined, 'sku')
            || str_contains($joined, 'is_fol')
            || str_contains($joined, 'eligible');
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>|null  $header
     * @return array{inventory_id: string, is_fol_eligible: bool, fol_category: ?string}|null
     */
    private function parseRow(array $cells, ?array $header, bool $defaultEligible): ?array
    {
        if ($header !== null) {
            $map = [];
            foreach ($header as $i => $key) {
                $map[$key] = $cells[$i] ?? '';
            }
            $id = $map['inventory_id']
                ?? $map['sku']
                ?? $map['item']
                ?? $map['inventoryid']
                ?? '';
            $eligibleRaw = $map['is_fol_eligible']
                ?? $map['eligible']
                ?? $map['fol_eligible']
                ?? ($defaultEligible ? '1' : '0');
            $category = $map['fol_category'] ?? $map['category'] ?? null;
        } else {
            // inventory_id [, is_fol_eligible] [, fol_category]
            $id = $cells[0] ?? '';
            $eligibleRaw = $cells[1] ?? ($defaultEligible ? '1' : '0');
            $category = $cells[2] ?? null;
        }

        $id = strtoupper(trim($id));
        if ($id === '') {
            return null;
        }

        return [
            'inventory_id' => $id,
            'is_fol_eligible' => $this->parseBool($eligibleRaw, $defaultEligible),
            'fol_category' => $category !== null && trim((string) $category) !== ''
                ? trim((string) $category)
                : null,
        ];
    }

    private function parseBool(string $value, bool $default): bool
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return $default;
        }
        if (in_array($v, ['1', 'true', 'yes', 'y', 'on', 'eligible'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function ensureAdmin(?\App\Models\User $user): void
    {
        if (! $user || (! $user->is_super_admin && $user->role !== 'Administrator')) {
            abort(403, 'Forbidden.');
        }
    }
}
