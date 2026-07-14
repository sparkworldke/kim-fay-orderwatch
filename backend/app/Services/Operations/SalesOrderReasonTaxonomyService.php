<?php

namespace App\Services\Operations;

use App\Models\SoReasonParent;
use App\Models\SoSubReason;
use Illuminate\Support\Facades\Schema;

class SalesOrderReasonTaxonomyService
{
    public function __construct(
        private readonly SalesOrderReasonCatalog $catalog,
    ) {
    }

    /**
     * @return array{
     *   parents: list<array{code: string, label: string, sub_reasons: list<array{code: string, label: string, hierarchical_label: string}>}>,
     *   sub_reasons: list<array{code: string, label: string}>,
     *   source: string
     * }
     */
    public function taxonomy(): array
    {
        if ($this->databaseReady()) {
            return [
                'parents' => $this->parentsFromDatabase(),
                'sub_reasons' => $this->subReasonsFromDatabase(),
                'source' => 'database',
            ];
        }

        return [
            'parents' => $this->parentsFromCatalog(),
            'sub_reasons' => $this->subReasonsFromCatalog(),
            'source' => 'catalog_constants',
        ];
    }

    /** @return list<string> */
    public function approvedSubReasonCodes(): array
    {
        if ($this->databaseReady()) {
            return SoSubReason::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('code')
                ->all();
        }

        return $this->catalog->approvedSubReasonCodes();
    }

    /**
     * Apply hierarchical workflow reason fields on a sales order from status + sub-reason code.
     *
     * @return array{workflow_parent_reason: ?string, workflow_sub_reason_code: ?string, workflow_reason_label: ?string, rejection_reason_code: ?string}
     */
    public function workflowAttributesForOrder(?string $status, ?string $subReasonCode): array
    {
        $parent = $this->catalog->parentForStatus($status);
        if ($parent === null || blank($subReasonCode)) {
            return [
                'workflow_parent_reason' => null,
                'workflow_sub_reason_code' => null,
                'workflow_reason_label' => null,
                'rejection_reason_code' => $subReasonCode,
            ];
        }

        $classified = $this->catalog->classify($parent, $subReasonCode);
        $canonical = $classified['sub_reason_code'];

        if ($classified['issue'] === SalesOrderReasonCatalog::ISSUE_VALID && $canonical !== null) {
            return [
                'workflow_parent_reason' => $parent,
                'workflow_sub_reason_code' => $canonical,
                'workflow_reason_label' => $this->catalog->formatHierarchical($parent, $canonical),
                'rejection_reason_code' => $canonical,
            ];
        }

        return [
            'workflow_parent_reason' => $parent,
            'workflow_sub_reason_code' => $canonical,
            'workflow_reason_label' => $classified['hierarchical_label'],
            'rejection_reason_code' => $canonical,
        ];
    }

    private function databaseReady(): bool
    {
        return Schema::hasTable('so_sub_reasons')
            && SoSubReason::query()->where('is_active', true)->exists();
    }

    /** @return list<array{code: string, label: string, sub_reasons: list<array{code: string, label: string, hierarchical_label: string}>}> */
    private function parentsFromDatabase(): array
    {
        return SoReasonParent::query()
            ->orderBy('sort_order')
            ->with(['subReasons' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(fn (SoReasonParent $parent) => [
                'code' => $parent->code,
                'label' => $parent->label,
                'sub_reasons' => $parent->subReasons->map(fn (SoSubReason $sub) => [
                    'code' => $sub->code,
                    'label' => $sub->label,
                    'hierarchical_label' => $this->catalog->formatHierarchical($parent->code, $sub->code),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{code: string, label: string}> */
    private function subReasonsFromDatabase(): array
    {
        return SoSubReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'label'])
            ->map(fn (SoSubReason $sub) => ['code' => $sub->code, 'label' => $sub->label])
            ->values()
            ->all();
    }

    /** @return list<array{code: string, label: string, sub_reasons: list<array{code: string, label: string, hierarchical_label: string}>}> */
    private function parentsFromCatalog(): array
    {
        $subs = collect(SalesOrderReasonCatalog::SUB_REASONS)
            ->map(fn (string $label, string $code) => [
                'code' => $code,
                'label' => $label,
            ])
            ->values()
            ->all();

        return collect(SalesOrderReasonCatalog::PARENT_LABELS)
            ->map(fn (string $label, string $code) => [
                'code' => $code,
                'label' => $label,
                'sub_reasons' => collect($subs)->map(fn (array $sub) => [
                    'code' => $sub['code'],
                    'label' => $sub['label'],
                    'hierarchical_label' => $this->catalog->formatHierarchical($code, $sub['code']),
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{code: string, label: string}> */
    private function subReasonsFromCatalog(): array
    {
        return collect(SalesOrderReasonCatalog::SUB_REASONS)
            ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
            ->values()
            ->all();
    }
}