<?php

namespace Database\Seeders;

use App\Models\AcumaticaInventoryItem;
use App\Models\ProductionMachine;
use App\Models\ProductionSkuPlan;
use App\Services\Production\ProductionSummaryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed production SKU plans from JSON only (no Excel required at runtime).
 *
 * Data file: database/seeders/data/stocks-production-plans.json
 * Generated from stocks-production Excel:
 * - 01 Kanban 2023.xlsx → Product Code, MSI, safety, buffer
 * - Machine Data.xlsx → machine, site (HQ|Tatu), segment (Consumer|Professional/KP)
 *
 * Inventory match: Product Code (e.g. COSTP0024) = acumatica_inventory_items.inventory_id
 *
 * Filters:
 * - site: HQ | Tatu
 * - business_line: Consumer Sales | Kim-Fay Professional
 *
 * Run:
 *   php artisan db:seed --class=ProductionPlanningStocksSeeder
 */
class ProductionPlanningStocksSeeder extends Seeder
{
    private const SITES = ['HQ', 'Tatu'];

    private const BUSINESS_LINES = ['Consumer Sales', 'Kim-Fay Professional'];

    public function run(): void
    {
        $path = database_path('seeders/data/stocks-production-plans.json');
        if (! is_file($path)) {
            $this->command?->error("Missing data file: {$path}");
            $this->command?->line('Generate it from stocks-production Excel files first.');

            return;
        }

        /** @var array{meta: array, plans: list<array>, machine_only_by_name?: list<array>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $plans = $payload['plans'] ?? [];
        $machineOnly = $payload['machine_only_by_name'] ?? [];

        if ($plans === []) {
            $this->command?->warn('No plans in stocks-production-plans.json');

            return;
        }

        $created = 0;
        $updated = 0;
        $unmatched = 0;
        $machineLinked = 0;
        $unmatchedCodes = [];

        // Preload inventory by upper inventory_id and by normalized description.
        $itemsById = AcumaticaInventoryItem::query()
            ->get(['id', 'inventory_id', 'description'])
            ->keyBy(fn (AcumaticaInventoryItem $item) => strtoupper(trim((string) $item->inventory_id)));

        $itemsByName = [];
        foreach ($itemsById as $item) {
            $key = $this->normalizeName($item->description);
            if ($key !== '') {
                $itemsByName[$key] = $item;
            }
        }

        DB::transaction(function () use (
            $plans,
            $machineOnly,
            $itemsById,
            $itemsByName,
            &$created,
            &$updated,
            &$unmatched,
            &$machineLinked,
            &$unmatchedCodes,
        ) {
            foreach ($plans as $row) {
                $code = strtoupper(trim((string) ($row['inventory_id'] ?? '')));
                if ($code === '') {
                    continue;
                }

                $item = $itemsById->get($code);
                if ($item === null) {
                    // Fallback: name match to inventory description
                    $nameKey = $this->normalizeName($row['product_name'] ?? null);
                    $item = $nameKey !== '' ? ($itemsByName[$nameKey] ?? null) : null;
                }

                if ($item === null) {
                    $unmatched++;
                    if (count($unmatchedCodes) < 40) {
                        $unmatchedCodes[] = $code;
                    }
                    continue;
                }

                $machines = array_values(array_filter(array_map(
                    static fn ($m) => trim((string) $m),
                    is_array($row['machines'] ?? null) ? $row['machines'] : [],
                )));
                $primaryMachine = $machines[0] ?? (isset($row['primary_machine']) ? trim((string) $row['primary_machine']) : null);

                $plan = ProductionSkuPlan::withTrashed()->firstOrNew([
                    'inventory_item_id' => $item->id,
                ]);
                $isNew = ! $plan->exists;
                if ($plan->trashed()) {
                    $plan->restore();
                }

                $plan->fill([
                    'ownership' => $row['ownership'] ?? 'manufactured',
                    // Filters: Site = HQ | Tatu; Business line = Consumer Sales | Kim-Fay Professional (KP)
                    'business_line' => $this->normalizeBusinessLine(
                        $row['business_line'] ?? $row['segment'] ?? null
                    ),
                    'site' => $this->normalizeSite($row['site'] ?? null),
                    'machine' => $primaryMachine ?: null,
                    'msi' => $this->nullableNumber($row['msi'] ?? null),
                    'safety_stock' => $this->nullableNumber($row['safety_stock'] ?? null),
                    'buffer_stock' => $this->nullableNumber($row['buffer_stock'] ?? null),
                ]);
                $plan->save();

                if ($machines !== []) {
                    $ids = [];
                    foreach ($machines as $name) {
                        $machine = ProductionMachine::query()->firstOrCreate(['name' => $name]);
                        $ids[] = $machine->id;
                    }
                    $plan->machines()->sync($ids);
                    $machineLinked += count($ids);
                }

                $isNew ? $created++ : $updated++;
            }

            // Machine-only rows (no Kanban code): match inventory by description name.
            foreach ($machineOnly as $row) {
                $nameKey = $this->normalizeName($row['product_name'] ?? null);
                if ($nameKey === '' || ! isset($itemsByName[$nameKey])) {
                    continue;
                }
                $item = $itemsByName[$nameKey];
                $machineName = trim((string) ($row['machine'] ?? ''));
                if ($machineName === '') {
                    continue;
                }

                $plan = ProductionSkuPlan::withTrashed()->firstOrNew([
                    'inventory_item_id' => $item->id,
                ]);
                $isNew = ! $plan->exists;
                if ($plan->trashed()) {
                    $plan->restore();
                }

                $businessLine = $this->normalizeBusinessLine(
                    $row['business_line'] ?? $row['segment'] ?? null
                ) ?? $plan->business_line;
                $site = $this->normalizeSite($row['site'] ?? null) ?? $plan->site;

                $plan->fill(array_filter([
                    'ownership' => $plan->ownership ?: 'manufactured',
                    'business_line' => $businessLine,
                    'site' => $site,
                    'machine' => $plan->machine ?: $machineName,
                ], static fn ($v) => $v !== null && $v !== ''));
                $plan->save();

                $machine = ProductionMachine::query()->firstOrCreate(['name' => $machineName]);
                $plan->machines()->syncWithoutDetaching([$machine->id]);
                $machineLinked++;
                $isNew ? $created++ : $updated++;
            }
        });

        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);

        $this->command?->info(sprintf(
            'Production planning seed: created=%d updated=%d unmatched_codes=%d machine_links=%d',
            $created,
            $updated,
            $unmatched,
            $machineLinked,
        ));
        if ($unmatchedCodes !== []) {
            $this->command?->warn(
                'Unmatched inventory_ids (first '.count($unmatchedCodes).'): '.implode(', ', $unmatchedCodes)
                .' — sync inventory from Acumatica first, then re-run seeder.'
            );
        }
        $this->command?->line(
            'Sources: Kanban Product Code → inventory_id; MSI = Max Standard Inventory; '
            .'safety = 04 Safety Stock; buffer = 03 Buffer Stock; machines from Machine Data.xlsx.'
        );
        $this->command?->line(
            'Filters seeded: site ∈ {HQ, Tatu}; business_line ∈ {Consumer Sales, Kim-Fay Professional} '
            .'(Excel Consumer → Consumer Sales; Professional/KP → Kim-Fay Professional).'
        );
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Site filter: HQ | Tatu only (matches Production MultiSelect).
     */
    private function normalizeSite(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = Str::lower(trim((string) $value));
        if (str_contains($s, 'tatu') || in_array($s, ['tp', 'tpark', 'tatu park'], true)) {
            return 'Tatu';
        }
        if (str_contains($s, 'hq') || str_contains($s, 'head office') || str_contains($s, 'nairobi')) {
            return 'HQ';
        }
        // Exact known values
        foreach (self::SITES as $site) {
            if (strcasecmp($site, trim((string) $value)) === 0) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Business-line filter: Consumer Sales | Kim-Fay Professional.
     * Excel Segment "Consumer" → Consumer Sales; "Professional" / KP → Kim-Fay Professional.
     */
    private function normalizeBusinessLine(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = Str::lower(trim((string) $value));

        if (
            str_contains($s, 'professional')
            || str_contains($s, 'proffessional')
            || $s === 'kp'
            || str_contains($s, 'kim-fay professional')
            || str_contains($s, 'kimfay professional')
            || str_contains($s, 'horeca')
        ) {
            return 'Kim-Fay Professional';
        }

        if (
            str_contains($s, 'consumer')
            || str_contains($s, 'retail')
            || $s === 'gt'
            || $s === 'mt'
            || str_contains($s, 'consumer sales')
        ) {
            return 'Consumer Sales';
        }

        foreach (self::BUSINESS_LINES as $line) {
            if (strcasecmp($line, trim((string) $value)) === 0) {
                return $line;
            }
        }

        return null;
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $s = Str::lower(trim($name));
        $s = str_replace(['é', 'è', 'ê', 'à', 'á'], ['e', 'e', 'e', 'a', 'a'], $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return $s;
    }
}
