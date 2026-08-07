<?php

namespace Database\Seeders;

use App\Models\AcumaticaInventoryItem;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Marks the FOL-eligible SKUs captured in the new-doc/acumatica_inventory_items.sql
 * snapshot as eligible in this app, matching the same fields FolProductsController
 * uses (is_fol_eligible, fol_category) and keyed the same way (inventory_id).
 *
 * Only sets eligibility for the SKUs listed in the source file — it does not touch
 * or clear eligibility on any other product, mirroring the existing bulk-upload
 * behavior in FolProductsController::bulkUpload().
 */
class FolEligibleProductsSync202608Seeder extends Seeder
{
    private const SOURCE_FILE = 'data/fol-eligible-products-2026-08.json';

    public function run(): void
    {
        $path = __DIR__.'/'.self::SOURCE_FILE;
        if (! is_file($path)) {
            throw new RuntimeException('Missing '.self::SOURCE_FILE);
        }

        /** @var array{items?: list<array{inventory_id: string, fol_category: ?string}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new RuntimeException('fol-eligible-products JSON has no items[].');
        }

        $updated = 0;
        $missing = [];

        foreach ($items as $row) {
            $inventoryId = strtoupper(trim((string) $row['inventory_id']));
            $item = AcumaticaInventoryItem::query()->where('inventory_id', $inventoryId)->first();

            if (! $item) {
                $missing[] = $inventoryId;

                continue;
            }

            $item->forceFill([
                'is_fol_eligible' => true,
                'fol_category' => $row['fol_category'] ?? $item->fol_category,
            ])->save();
            $updated++;
        }

        $this->command?->info("FOL eligible products sync: {$updated} of ".count($items).' SKUs updated.');
        if ($missing !== []) {
            $this->command?->warn('SKUs not found (sync inventory from Acumatica first): '.implode(', ', array_slice($missing, 0, 25)).(count($missing) > 25 ? ' …' : ''));
        }
    }
}
