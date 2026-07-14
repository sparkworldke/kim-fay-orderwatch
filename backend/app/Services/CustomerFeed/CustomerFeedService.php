<?php

namespace App\Services\CustomerFeed;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\MailboxFolder;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerFeedService
{
    /**
     * @return array{
     *   date_from: string,
     *   date_to: string,
     *   summary: array<string, int|float|null>,
     *   groups: list<array<string, mixed>>
     * }
     */
    /**
     * @param  list<string>|null  $scopedCustomerIds
     */
    public function listGroups(string $dateFrom, string $dateTo, ?string $search = null, ?array $scopedCustomerIds = null): array
    {
        $groups = $this->buildGroupIndex($scopedCustomerIds);
        $acumaticaIds = $groups->flatMap(fn (array $g) => $g['acumatica_ids'])->unique()->values()->all();

        $orderStats = $this->orderStatsByCustomer($dateFrom, $dateTo, $acumaticaIds);
        $emailStats = $this->emailStatsByCustomer($dateFrom, $dateTo, $acumaticaIds);
        $fillStats  = $this->fillRateByCustomer($dateFrom, $dateTo, $acumaticaIds);

        $payload = [];
        foreach ($groups as $groupKey => $group) {
            $ids = $group['acumatica_ids'];
            $orderCount = 0;
            $matchedOrders = 0;
            $completionHours = [];
            $fillShipped = 0.0;
            $fillOrdered = 0.0;
            $emailCount = 0;
            $branches = [];

            foreach ($ids as $id) {
                $o = $orderStats[$id] ?? null;
                $e = $emailStats[$id] ?? null;
                $f = $fillStats[$id] ?? null;

                $branchOrders = (int) ($o['order_count'] ?? 0);
                $branchMatched = (int) ($o['matched_orders'] ?? 0);
                $branchEmails = (int) ($e['email_count'] ?? 0);

                $orderCount += $branchOrders;
                $matchedOrders += $branchMatched;
                $emailCount += $branchEmails;

                if ($o && $o['avg_completion_hours'] !== null) {
                    $completionHours[] = (float) $o['avg_completion_hours'];
                }

                if ($f) {
                    $fillShipped += (float) $f['shipped'];
                    $fillOrdered += (float) $f['ordered'];
                }

                if (count($ids) > 1) {
                    $branches[] = [
                        'acumatica_id'          => $id,
                        'name'                  => $o['customer_name'] ?? $group['names'][$id] ?? $id,
                        'order_count'           => $branchOrders,
                        'email_count'           => $branchEmails,
                        'matched_orders'        => $branchMatched,
                        'avg_completion_hours'  => $o['avg_completion_hours'] ?? null,
                        'avg_fill_rate_pct'     => $f['fill_rate_pct'] ?? null,
                    ];
                }
            }

            $displayName = $group['display_name'];
            if ($search !== null && $search !== '') {
                $haystack = strtolower($displayName.' '.implode(' ', $ids));
                if (! str_contains($haystack, strtolower($search))) {
                    continue;
                }
            }

            $avgCompletion = $completionHours !== []
                ? round(array_sum($completionHours) / count($completionHours), 1)
                : null;

            $avgFillRate = SalesOrderLineFulfillmentDeriver::safeFillRate($fillShipped, $fillOrdered);

            $payload[] = [
                'group_key'             => $groupKey,
                'display_name'          => $displayName,
                'is_grouped'            => count($ids) > 1,
                'branch_count'          => max(0, count($ids) - 1),
                'acumatica_ids'         => $ids,
                'order_count'           => $orderCount,
                'email_count'           => $emailCount,
                'matched_orders'        => $matchedOrders,
                'avg_completion_hours'  => $avgCompletion,
                'avg_fill_rate_pct'     => $avgFillRate,
                'branches'              => $branches,
            ];
        }

        usort($payload, fn ($a, $b) => $b['order_count'] <=> $a['order_count']);

        $summary = [
            'group_count'    => count($payload),
            'order_count'    => array_sum(array_column($payload, 'order_count')),
            'email_count'    => array_sum(array_column($payload, 'email_count')),
            'matched_orders' => array_sum(array_column($payload, 'matched_orders')),
        ];

        return [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'summary'   => $summary,
            'groups'    => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<string>|null  $scopedCustomerIds
     */
    public function insights(string $groupKey, string $dateFrom, string $dateTo, ?array $scopedCustomerIds = null): array
    {
        $groups = $this->buildGroupIndex($scopedCustomerIds);
        if (! isset($groups[$groupKey])) {
            abort(404, 'Customer group not found');
        }

        $group = $groups[$groupKey];
        $ids = $group['acumatica_ids'];

        if ($ids === []) {
            abort(404, 'Customer group not found');
        }

        $issues = $this->aggregateConflictIssues($ids, $dateFrom, $dateTo);
        $fillIssues = $this->fillRateIssues($ids, $dateFrom, $dateTo);
        $matchIssues = $this->matchStatusIssues($ids, $dateFrom, $dateTo);

        return [
            'group_key'    => $groupKey,
            'display_name' => $group['display_name'],
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'issues'       => array_values(array_filter([
                $issues['amount_positive'],
                $issues['amount_negative'],
                $issues['quantity'],
                $issues['unit_price'],
                $issues['branch'],
                $issues['delivery_date'],
                $issues['currency'],
                $issues['sku'],
                $fillIssues,
                $matchIssues['unmatched_emails'],
                $matchIssues['needs_review'],
            ])),
            'issue_total' => collect($issues)->merge([$fillIssues, $matchIssues['unmatched_emails'], $matchIssues['needs_review']])
                ->sum(fn ($i) => is_array($i) ? ($i['count'] ?? 0) : 0),
        ];
    }

    /**
     * @param  list<string>|null  $scopedCustomerIds
     * @return Collection<string, array<string, mixed>>
     */
    private function buildGroupIndex(?array $scopedCustomerIds = null): Collection
    {
        $customersQuery = AcumaticaCustomer::query()
            ->orderByDesc('is_main_account')
            ->orderBy('name');

        if ($scopedCustomerIds !== null) {
            if ($scopedCustomerIds === []) {
                return collect();
            }

            $customersQuery->whereIn('acumatica_id', $scopedCustomerIds);
        }

        $customers = $customersQuery->get(['id', 'acumatica_id', 'name', 'parent_acumatica_id', 'is_main_account']);

        $byAcumaticaId = $customers->keyBy('acumatica_id');
        $memberToRoot = [];

        foreach ($customers as $customer) {
            $root = $customer->parent_acumatica_id && $byAcumaticaId->has($customer->parent_acumatica_id)
                ? $customer->parent_acumatica_id
                : $customer->acumatica_id;
            $memberToRoot[$customer->acumatica_id] = $root;
        }

        $orderNamesQuery = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->whereNotNull('customer_acumatica_id')
            ->select(['customer_acumatica_id', 'customer_name'])
            ->distinct();

        if ($scopedCustomerIds !== null) {
            $orderNamesQuery->whereIn('customer_acumatica_id', $scopedCustomerIds);
        }

        $orderNames = $orderNamesQuery->get();

        foreach ($orderNames as $row) {
            $id = $row->customer_acumatica_id;
            if (! $id || isset($memberToRoot[$id])) {
                continue;
            }
            $memberToRoot[$id] = $id;
            if (! $byAcumaticaId->has($id)) {
                $byAcumaticaId[$id] = (object) [
                    'acumatica_id' => $id,
                    'name'          => $row->customer_name ?? $id,
                ];
            }
        }

        $nameBuckets = [];
        foreach ($memberToRoot as $id => $root) {
            if ($id !== $root) {
                continue;
            }
            $name = $byAcumaticaId[$id]->name ?? $id;
            $bucket = $this->normalizeGroupName($name);
            $nameBuckets[$bucket][] = $id;
        }

        foreach ($nameBuckets as $bucket => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $primary = $this->pickPrimaryId($ids, $byAcumaticaId);
            foreach ($ids as $id) {
                if ($id !== $primary) {
                    $memberToRoot[$id] = $primary;
                }
            }
        }

        $groups = [];
        foreach ($memberToRoot as $id => $root) {
            if (! isset($groups[$root])) {
                $main = $byAcumaticaId[$root] ?? null;
                $groups[$root] = [
                    'display_name' => $main->name ?? $this->normalizeGroupName($byAcumaticaId[$id]->name ?? $root),
                    'acumatica_ids' => [],
                    'names'         => [],
                ];
            }
            if (! in_array($id, $groups[$root]['acumatica_ids'], true)) {
                $groups[$root]['acumatica_ids'][] = $id;
                $groups[$root]['names'][$id] = $byAcumaticaId[$id]->name ?? $id;
            }
        }

        foreach ($groups as $root => &$group) {
            if (count($group['acumatica_ids']) > 1) {
                $group['display_name'] = $this->normalizeGroupName($group['display_name']);
            }
        }

        return collect($groups);
    }

    /**
     * @param  list<string>  $acumaticaIds
     * @return array<string, array<string, mixed>>
     */
    private function orderStatsByCustomer(string $dateFrom, string $dateTo, array $acumaticaIds): array
    {
        if ($acumaticaIds === []) {
            return [];
        }

        $completionHours = $this->completionHoursSql('order_date', 'completed_at');

        $rows = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->whereIn('customer_acumatica_id', $acumaticaIds)
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->select([
                'customer_acumatica_id',
                DB::raw('MAX(customer_name) as customer_name'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw("SUM(CASE WHEN match_status IN ('matched', 'matched_discrepancies') THEN 1 ELSE 0 END) as matched_orders"),
                DB::raw("AVG(CASE WHEN completed_at IS NOT NULL THEN {$completionHours} END) as avg_completion_hours"),
            ])
            ->groupBy('customer_acumatica_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->customer_acumatica_id] = [
                'customer_name'         => $row->customer_name,
                'order_count'           => (int) $row->order_count,
                'matched_orders'        => (int) $row->matched_orders,
                'avg_completion_hours'  => $row->avg_completion_hours !== null ? round((float) $row->avg_completion_hours, 1) : null,
            ];
        }

        return $map;
    }

    /**
     * @param  list<string>  $acumaticaIds
     * @return array<string, array<string, int>>
     */
    private function emailStatsByCustomer(string $dateFrom, string $dateTo, array $acumaticaIds): array
    {
        if ($acumaticaIds === []) {
            return [];
        }

        $customerDbIds = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $acumaticaIds)
            ->pluck('id', 'acumatica_id');

        $folderIdsByCustomer = MailboxFolder::query()
            ->whereIn('customer_id', $customerDbIds->values())
            ->get(['id', 'customer_id'])
            ->groupBy('customer_id');

        $fromOrder = Email::query()
            ->join('acumatica_sales_orders as o', 'emails.matched_order_id', '=', 'o.id')
            ->whereIn('o.customer_acumatica_id', $acumaticaIds)
            ->whereDate('emails.received_at', '>=', $dateFrom)
            ->whereDate('emails.received_at', '<=', $dateTo)
            ->select(['o.customer_acumatica_id', DB::raw('COUNT(DISTINCT emails.id) as email_count')])
            ->groupBy('o.customer_acumatica_id')
            ->pluck('email_count', 'customer_acumatica_id');

        $fromFolder = [];
        foreach ($customerDbIds as $acumaticaId => $dbId) {
            $folderIds = ($folderIdsByCustomer[$dbId] ?? collect())->pluck('id');
            if ($folderIds->isEmpty()) {
                continue;
            }
            $fromFolder[$acumaticaId] = (int) Email::query()
                ->whereIn('mailbox_folder_id', $folderIds)
                ->whereDate('received_at', '>=', $dateFrom)
                ->whereDate('received_at', '<=', $dateTo)
                ->distinct('id')
                ->count('id');
        }

        $map = [];
        foreach ($acumaticaIds as $id) {
            $map[$id] = [
                'email_count' => max((int) ($fromOrder[$id] ?? 0), (int) ($fromFolder[$id] ?? 0)),
            ];
        }

        return $map;
    }

    /**
     * @param  list<string>  $acumaticaIds
     * @return array<string, array{shipped: float, ordered: float, fill_rate_pct: ?float}>
     */
    private function fillRateByCustomer(string $dateFrom, string $dateTo, array $acumaticaIds): array
    {
        if ($acumaticaIds === []) {
            return [];
        }

        $rows = DB::table('acumatica_fill_rate_snapshots as f')
            ->join('acumatica_sales_orders as o', 'f.sales_order_id', '=', 'o.id')
            ->whereIn('f.customer_acumatica_id', $acumaticaIds)
            ->where('f.fill_rate_status', '!=', 'na')
            ->whereDate('o.order_date', '>=', $dateFrom)
            ->whereDate('o.order_date', '<=', $dateTo)
            ->select([
                'f.customer_acumatica_id',
                DB::raw('SUM(f.total_shipped_qty) as shipped'),
                DB::raw('SUM(f.total_ordered_qty) as ordered'),
            ])
            ->groupBy('f.customer_acumatica_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $shipped = (float) $row->shipped;
            $ordered = (float) $row->ordered;
            $map[$row->customer_acumatica_id] = [
                'shipped'        => $shipped,
                'ordered'        => $ordered,
                'fill_rate_pct'  => SalesOrderLineFulfillmentDeriver::safeFillRate($shipped, $ordered),
            ];
        }

        return $map;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>|null>
     */
    private function aggregateConflictIssues(array $ids, string $dateFrom, string $dateTo): array
    {
        $buckets = [
            'amount_positive' => ['type' => 'amount_discrepancy_positive', 'label' => 'Amount higher on Acumatica (+)', 'count' => 0, 'examples' => []],
            'amount_negative' => ['type' => 'amount_discrepancy_negative', 'label' => 'Amount lower on Acumatica (−)', 'count' => 0, 'examples' => []],
            'quantity'        => ['type' => 'quantity', 'label' => 'Quantity mismatch', 'count' => 0, 'examples' => []],
            'unit_price'      => ['type' => 'unit_price', 'label' => 'Unit price mismatch', 'count' => 0, 'examples' => []],
            'branch'          => ['type' => 'branch', 'label' => 'Branch / location mismatch', 'count' => 0, 'examples' => []],
            'delivery_date'   => ['type' => 'delivery_date', 'label' => 'Delivery date mismatch', 'count' => 0, 'examples' => []],
            'currency'        => ['type' => 'currency', 'label' => 'Currency mismatch', 'count' => 0, 'examples' => []],
            'sku'             => ['type' => 'sku', 'label' => 'SKU / item not on order', 'count' => 0, 'examples' => []],
        ];

        $emails = Email::query()
            ->with(['matchedOrder:id,acumatica_order_nbr,customer_name'])
            ->whereHas('matchedOrder', function ($q) use ($ids, $dateFrom, $dateTo) {
                $q->salesOrdersOnly()
                    ->whereIn('customer_acumatica_id', $ids)
                    ->whereDate('order_date', '>=', $dateFrom)
                    ->whereDate('order_date', '<=', $dateTo);
            })
            ->whereNotNull('match_conflicts')
            ->get(['id', 'subject', 'matched_order_id', 'match_conflicts']);

        foreach ($emails as $email) {
            $conflicts = is_array($email->match_conflicts) ? $email->match_conflicts : [];
            foreach ($conflicts as $conflict) {
                if (! is_array($conflict)) {
                    continue;
                }

                $field = (string) ($conflict['field'] ?? '');
                $example = [
                    'order_nbr' => $email->matchedOrder?->acumatica_order_nbr,
                    'subject'   => $email->subject,
                    'field'     => $field,
                    'email'     => $conflict['email_value'] ?? null,
                    'acumatica' => $conflict['acumatica_value'] ?? null,
                    'amount_delta' => $conflict['amount_delta'] ?? null,
                ];

                if ($field === 'total') {
                    $delta = $this->parseAmountDelta($conflict);
                    if ($delta === null) {
                        continue;
                    }
                    $key = $delta >= 0 ? 'amount_positive' : 'amount_negative';
                    $buckets[$key]['count']++;
                    $this->pushExample($buckets[$key]['examples'], $example);
                } elseif (str_starts_with($field, 'quantity:')) {
                    $buckets['quantity']['count']++;
                    $this->pushExample($buckets['quantity']['examples'], $example);
                } elseif (str_starts_with($field, 'unit_price:')) {
                    $buckets['unit_price']['count']++;
                    $this->pushExample($buckets['unit_price']['examples'], $example);
                } elseif ($field === 'branch') {
                    $buckets['branch']['count']++;
                    $this->pushExample($buckets['branch']['examples'], $example);
                } elseif ($field === 'delivery_date') {
                    $buckets['delivery_date']['count']++;
                    $this->pushExample($buckets['delivery_date']['examples'], $example);
                } elseif ($field === 'currency') {
                    $buckets['currency']['count']++;
                    $this->pushExample($buckets['currency']['examples'], $example);
                } elseif ($field === 'sku') {
                    $buckets['sku']['count']++;
                    $this->pushExample($buckets['sku']['examples'], $example);
                }
            }
        }

        return array_map(fn (array $bucket) => $bucket['count'] > 0 ? $bucket : null, $buckets);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>|null
     */
    private function fillRateIssues(array $ids, string $dateFrom, string $dateTo): ?array
    {
        $count = (int) DB::table('acumatica_fill_rate_snapshots as f')
            ->join('acumatica_sales_orders as o', 'f.sales_order_id', '=', 'o.id')
            ->whereIn('f.customer_acumatica_id', $ids)
            ->whereIn('f.fill_rate_status', ['critical', 'at_risk'])
            ->whereDate('o.order_date', '>=', $dateFrom)
            ->whereDate('o.order_date', '<=', $dateTo)
            ->count();

        if ($count === 0) {
            return null;
        }

        $examples = DB::table('acumatica_fill_rate_snapshots as f')
            ->join('acumatica_sales_orders as o', 'f.sales_order_id', '=', 'o.id')
            ->whereIn('f.customer_acumatica_id', $ids)
            ->whereIn('f.fill_rate_status', ['critical', 'at_risk'])
            ->whereDate('o.order_date', '>=', $dateFrom)
            ->whereDate('o.order_date', '<=', $dateTo)
            ->orderBy('f.fill_rate_pct')
            ->limit(5)
            ->get(['f.order_nbr', 'f.fill_rate_pct', 'f.fill_rate_status', 'f.total_ordered_qty', 'f.total_shipped_qty'])
            ->map(fn ($row) => [
                'order_nbr'     => $row->order_nbr,
                'fill_rate_pct' => $row->fill_rate_pct,
                'status'        => $row->fill_rate_status,
                'ordered'       => $row->total_ordered_qty,
                'shipped'       => $row->total_shipped_qty,
            ])
            ->all();

        return [
            'type'     => 'low_fill_rate',
            'label'    => 'Low fill rate (quantity fulfilment)',
            'count'    => $count,
            'examples' => $examples,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array{unmatched_emails: ?array, needs_review: ?array}
     */
    private function matchStatusIssues(array $ids, string $dateFrom, string $dateTo): array
    {
        $unmatched = (int) Email::query()
            ->whereHas('matchedOrder', function ($q) use ($ids, $dateFrom, $dateTo) {
                $q->salesOrdersOnly()
                    ->whereIn('customer_acumatica_id', $ids)
                    ->where('match_status', 'unmatched')
                    ->whereDate('order_date', '>=', $dateFrom)
                    ->whereDate('order_date', '<=', $dateTo);
            })
            ->count();

        $needsReview = (int) AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->whereIn('customer_acumatica_id', $ids)
            ->where('match_status', 'needs_review')
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->count();

        return [
            'unmatched_emails' => $unmatched > 0 ? [
                'type'  => 'unmatched',
                'label' => 'Unmatched orders',
                'count' => $unmatched,
                'examples' => [],
            ] : null,
            'needs_review' => $needsReview > 0 ? [
                'type'  => 'needs_review',
                'label' => 'Orders needing review',
                'count' => $needsReview,
                'examples' => [],
            ] : null,
        ];
    }

    private function normalizeGroupName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^([^\-–]+)/u', $name, $matches)) {
            return trim($matches[1]);
        }

        return $name;
    }

    /** @param  Collection<string, object>  $byAcumaticaId */
    private function pickPrimaryId(array $ids, Collection $byAcumaticaId): string
    {
        foreach ($ids as $id) {
            $customer = $byAcumaticaId[$id] ?? null;
            if ($customer && ($customer->is_main_account ?? false)) {
                return $id;
            }
        }

        return $ids[0];
    }

    /** @param  array<string, mixed>  $conflict */
    private function parseAmountDelta(array $conflict): ?float
    {
        if (isset($conflict['amount_delta'])) {
            $parsed = (float) str_replace(',', '', (string) $conflict['amount_delta']);

            return is_finite($parsed) ? $parsed : null;
        }

        $acumatica = (float) str_replace(',', '', (string) ($conflict['acumatica_value'] ?? ''));
        $email = (float) str_replace(',', '', (string) ($conflict['email_value_inc_vat'] ?? $conflict['email_value'] ?? ''));

        if (! is_finite($acumatica) || ! is_finite($email)) {
            return null;
        }

        return $acumatica - $email;
    }

    /** @param  list<array<string, mixed>>  $examples */
    private function pushExample(array &$examples, array $example): void
    {
        if (count($examples) >= 5) {
            return;
        }
        $examples[] = $example;
    }

    private function completionHoursSql(string $startColumn, string $endColumn): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "(julianday({$endColumn}) - julianday({$startColumn})) * 24",
            'pgsql'  => "EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn})) / 3600",
            default  => "TIMESTAMPDIFF(HOUR, {$startColumn}, {$endColumn})",
        };
    }
}