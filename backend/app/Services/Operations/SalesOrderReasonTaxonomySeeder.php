<?php

namespace App\Services\Operations;

use App\Models\SoReasonAlias;
use App\Models\SoReasonParent;
use App\Models\SoSubReason;
use Illuminate\Support\Facades\DB;

class SalesOrderReasonTaxonomySeeder
{
    public function __construct(
        private readonly SalesOrderReasonCatalog $catalog,
    ) {
    }

    /**
     * @return array{parents: int, sub_reasons: int, links: int, aliases: int}
     */
    public function seed(): array
    {
        return DB::transaction(function () {
            $parentIds = [];
            $sort = 0;
            foreach (SalesOrderReasonCatalog::PARENT_LABELS as $code => $label) {
                $parent = SoReasonParent::updateOrCreate(
                    ['code' => $code],
                    ['label' => $label, 'sort_order' => $sort++],
                );
                $parentIds[$code] = $parent->id;
            }

            $subIds = [];
            $subSort = 0;
            foreach (SalesOrderReasonCatalog::SUB_REASONS as $code => $label) {
                $sub = SoSubReason::updateOrCreate(
                    ['code' => $code],
                    ['label' => $label, 'sort_order' => $subSort++, 'is_active' => true],
                );
                $subIds[$code] = $sub->id;
            }

            $linkCount = 0;
            foreach ($parentIds as $parentId) {
                foreach ($subIds as $subId) {
                    DB::table('so_reason_parent_sub_reason')->updateOrInsert(
                        ['parent_id' => $parentId, 'sub_reason_id' => $subId],
                        [],
                    );
                    $linkCount++;
                }
            }

            $aliasCount = 0;
            foreach (SalesOrderReasonCatalog::ACUMATICA_ALIASES as $alias => $subCode) {
                SoReasonAlias::updateOrCreate(
                    ['alias' => $alias],
                    ['sub_reason_code' => $subCode],
                );
                $aliasCount++;
            }

            return [
                'parents' => count($parentIds),
                'sub_reasons' => count($subIds),
                'links' => $linkCount,
                'aliases' => $aliasCount,
            ];
        });
    }
}