<?php

namespace App\Services\Operations;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use Illuminate\Support\Collection;

class SoReasonAuditService
{
    public function __construct(
        private readonly SalesOrderReasonCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $workflowStatuses = [
            'cancelled' => ['Canceled', 'Cancelled'],
            'rejected' => ['Rejected'],
            'on_hold' => ['On Hold', 'Credit Hold'],
        ];

        $workflowAudit = [];
        foreach ($workflowStatuses as $key => $statuses) {
            $query = AcumaticaSalesOrder::query()
                ->where('order_type', 'SO')
                ->whereIn('status', $statuses);

            $total = (clone $query)->count();
            $withWorkflow = (clone $query)
                ->whereNotNull('workflow_sub_reason_code')
                ->where('workflow_sub_reason_code', '!=', '')
                ->count();
            $withLegacyCode = (clone $query)
                ->whereNotNull('rejection_reason_code')
                ->where('rejection_reason_code', '!=', '')
                ->count();
            $withText = (clone $query)
                ->where(function ($q) {
                    $q->whereNotNull('rejection_reason')->where('rejection_reason', '!=', '')
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('on_hold_reason')->where('on_hold_reason', '!=', '');
                        });
                })
                ->count();
            $missing = $total - $withWorkflow;

            $workflowAudit[$key] = [
                'statuses' => $statuses,
                'total_orders' => $total,
                'with_hierarchical_reason' => $withWorkflow,
                'with_legacy_reason_code' => $withLegacyCode,
                'with_free_text_only' => max(0, $withText - $withWorkflow),
                'missing_reason' => max(0, $missing),
                'capture_rate_pct' => $total > 0 ? round(($withWorkflow / $total) * 1000) / 10 : null,
            ];
        }

        $backorderCodes = AcumaticaBackorderLine::query()
            ->whereNotNull('reason_code')
            ->where('reason_code', '!=', '')
            ->distinct()
            ->pluck('reason_code');

        $fillRateCodes = AcumaticaSalesOrderLine::query()
            ->whereNotNull('unfilled_reason_code')
            ->where('unfilled_reason_code', '!=', '')
            ->distinct()
            ->pluck('unfilled_reason_code');

        $allObserved = $backorderCodes
            ->merge($fillRateCodes)
            ->merge(
                AcumaticaSalesOrder::query()
                    ->whereNotNull('workflow_sub_reason_code')
                    ->distinct()
                    ->pluck('workflow_sub_reason_code')
            )
            ->merge(
                AcumaticaSalesOrder::query()
                    ->whereNotNull('rejection_reason_code')
                    ->distinct()
                    ->pluck('rejection_reason_code')
            );

        $coverage = $this->catalog->auditRequiredCoverage($allObserved);

        $requiredReasons = collect($this->catalog->approvedSubReasonCodes())
            ->map(fn (string $code) => [
                'sub_reason_code' => $code,
                'label' => $this->catalog->subReasonLabel($code),
                'observed_in_data' => in_array($code, $coverage['observed_approved'], true),
                'sources' => $this->sourcesForSubReason($code, $backorderCodes, $fillRateCodes),
            ])
            ->values()
            ->all();

        $breakdown = $this->buildBreakdown();

        return [
            'generated_at' => now()->toIso8601String(),
            'taxonomy' => [
                'parent_contexts' => SalesOrderReasonCatalog::PARENT_LABELS,
                'required_sub_reason_count' => count(SalesOrderReasonCatalog::SUB_REASONS),
                'display_format' => '{Parent Label} - {Sub-Reason Label}',
            ],
            'workflow_orders' => $workflowAudit,
            'backorder_and_fill_rate' => [
                'backorder_distinct_codes' => $backorderCodes->values()->all(),
                'fill_rate_distinct_codes' => $fillRateCodes->values()->all(),
                'backorder_lines_total' => AcumaticaBackorderLine::count(),
                'backorder_lines_with_reason' => AcumaticaBackorderLine::query()
                    ->whereNotNull('reason_code')->where('reason_code', '!=', '')->count(),
                'backorder_lines_missing_reason' => AcumaticaBackorderLine::query()
                    ->where(fn ($q) => $q->whereNull('reason_code')->orWhere('reason_code', ''))->count(),
            ],
            'required_reason_coverage' => $coverage,
            'required_reasons' => $requiredReasons,
            'breakdown_by_parent_and_sub_reason' => $breakdown,
            'flagged_unclassified' => $this->flaggedUnclassified(),
            'gaps_summary' => [
                'workflow_orders_missing_hierarchical_reason' => collect($workflowAudit)->sum('missing_reason'),
                'required_reasons_not_observed' => count($coverage['missing_required']),
                'unclassified_codes_in_data' => count($coverage['unclassified_observed']),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildBreakdown(): array
    {
        $rows = [];

        foreach (AcumaticaSalesOrder::query()
            ->where('order_type', 'SO')
            ->whereNotNull('workflow_parent_reason')
            ->selectRaw('workflow_parent_reason, workflow_sub_reason_code, count(*) as order_count')
            ->groupBy('workflow_parent_reason', 'workflow_sub_reason_code')
            ->get() as $row) {
            $parent = (string) $row->workflow_parent_reason;
            $sub = (string) $row->workflow_sub_reason_code;
            $rows[] = [
                'parent_reason' => $this->catalog->parentLabel($parent),
                'parent_reason_code' => $parent,
                'sub_reason_code' => $sub,
                'sub_reason_label' => $this->catalog->subReasonLabel($sub),
                'hierarchical_label' => $this->catalog->formatHierarchical($parent, $sub),
                'order_count' => (int) $row->order_count,
                'line_count' => 0,
                'source' => 'workflow_order',
            ];
        }

        foreach (AcumaticaBackorderLine::query()
            ->whereNotNull('reason_code')
            ->where('reason_code', '!=', '')
            ->selectRaw('reason_code, count(*) as line_count')
            ->groupBy('reason_code')
            ->get() as $row) {
            $raw = (string) $row->reason_code;
            $classified = $this->catalog->classify(SalesOrderReasonCatalog::PARENT_BACKORDER, $raw);
            $rows[] = [
                'parent_reason' => $classified['parent_reason_label'],
                'parent_reason_code' => SalesOrderReasonCatalog::PARENT_BACKORDER,
                'sub_reason_code' => $classified['sub_reason_code'],
                'sub_reason_label' => $classified['sub_reason_label'],
                'hierarchical_label' => $classified['hierarchical_label'],
                'order_count' => 0,
                'line_count' => (int) $row->line_count,
                'source' => 'backorder',
                'issue' => $classified['issue'],
            ];
        }

        return collect($rows)
            ->sortByDesc(fn ($r) => ($r['order_count'] ?? 0) + ($r['line_count'] ?? 0))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function flaggedUnclassified(): array
    {
        $flagged = [];

        foreach (AcumaticaBackorderLine::query()
            ->whereNotNull('reason_code')
            ->where('reason_code', '!=', '')
            ->selectRaw('reason_code, count(*) as c')
            ->groupBy('reason_code')
            ->get() as $row) {
            $raw = (string) $row->reason_code;
            $classified = $this->catalog->classify(SalesOrderReasonCatalog::PARENT_BACKORDER, $raw);
            if ($classified['issue'] !== SalesOrderReasonCatalog::ISSUE_VALID) {
                $flagged[] = [
                    'source' => 'backorder',
                    'raw_code' => $raw,
                    'issue' => $classified['issue'],
                    'line_count' => (int) $row->c,
                ];
            }
        }

        foreach (['Canceled', 'Cancelled', 'Rejected', 'On Hold', 'Credit Hold'] as $status) {
            foreach (AcumaticaSalesOrder::query()
                ->where('order_type', 'SO')
                ->where('status', $status)
                ->whereNull('workflow_sub_reason_code')
                ->limit(20)
                ->get(['acumatica_order_nbr', 'status', 'rejection_reason', 'on_hold_reason']) as $order) {
                $flagged[] = [
                    'source' => 'workflow_order',
                    'order_nbr' => $order->acumatica_order_nbr,
                    'status' => $order->status,
                    'issue' => SalesOrderReasonCatalog::ISSUE_MISSING,
                    'free_text' => $order->rejection_reason ?? $order->on_hold_reason,
                ];
            }
        }

        return array_slice($flagged, 0, 100);
    }

    /**
     * @param  Collection<int, string>  $backorderCodes
     * @param  Collection<int, string>  $fillRateCodes
     * @return list<string>
     */
    private function sourcesForSubReason(string $subCode, Collection $backorderCodes, Collection $fillRateCodes): array
    {
        $sources = [];

        foreach ($backorderCodes as $raw) {
            if ($this->catalog->resolveSubReason((string) $raw) === $subCode) {
                $sources[] = 'backorder';
                break;
            }
        }

        foreach ($fillRateCodes as $raw) {
            if ($this->catalog->resolveSubReason((string) $raw) === $subCode) {
                $sources[] = 'fill_rate';
                break;
            }
        }

        return array_values(array_unique($sources));
    }
}