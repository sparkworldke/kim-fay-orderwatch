<?php

namespace App\Console\Commands;

use App\Models\AcumaticaInventoryItem;
use Illuminate\Console\Command;

/**
 * Populate last_cost / average_cost / sales_price from stored StockItem raw_payload.
 *
 * Many items were synced (or stocks-only refreshed) with cost fields present in the
 * Acumatica payload but never written to dedicated columns — PCR base price then
 * resolves to 0.
 */
class BackfillInventoryCosts extends Command
{
    protected $signature = 'inventory:backfill-costs
                            {--dry-run : Report how many rows would update without writing}
                            {--limit=0 : Max rows to process (0 = all)}';

    protected $description = 'Backfill inventory last_cost, average_cost, and sales_price from raw_payload';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $updated = 0;
        $scanned = 0;
        $skippedNoRaw = 0;
        $skippedNoChange = 0;

        $query = AcumaticaInventoryItem::query()->whereNotNull('raw_payload')->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $item) {
            $scanned++;
            $raw = $this->decodeRaw($item->raw_payload);
            if ($raw === null) {
                $skippedNoRaw++;
                continue;
            }

            $lastCost = $this->floatFromRaw($raw, ['LastCost']);
            $avgCost = $this->floatFromRaw($raw, ['AverageCost']);
            $sales = $this->floatFromRaw($raw, ['SalesPrice', 'DefaultPrice']);

            $patch = [];
            if ($lastCost !== null && $lastCost > 0 && (float) ($item->last_cost ?? 0) <= 0) {
                $patch['last_cost'] = $lastCost;
            }
            if ($avgCost !== null && $avgCost > 0 && (float) ($item->average_cost ?? 0) <= 0) {
                $patch['average_cost'] = $avgCost;
            }
            if ($sales !== null && $sales > 0 && (float) ($item->sales_price ?? 0) <= 0) {
                $patch['sales_price'] = $sales;
            }

            if ($patch === []) {
                $skippedNoChange++;
                continue;
            }

            $updated++;
            if (! $dryRun) {
                $item->forceFill($patch)->save();
            } elseif ($updated <= 5) {
                $this->line("Would update {$item->inventory_id}: ".json_encode($patch));
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."scanned={$scanned} updated={$updated} no_raw={$skippedNoRaw} no_change={$skippedNoChange}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function decodeRaw(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '' || $raw === 'null' || $raw === '{}') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $keys
     */
    private function floatFromRaw(array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $field = $raw[$key];
            $value = is_array($field) ? ($field['value'] ?? null) : $field;
            if ($value === null || $value === '') {
                continue;
            }
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
