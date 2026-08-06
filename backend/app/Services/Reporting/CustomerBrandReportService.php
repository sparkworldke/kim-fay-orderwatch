<?php

namespace App\Services\Reporting;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Services\Admin\FillRateCalculator;
use App\Services\Admin\ProductBrandClassifier;
use App\Services\Operations\FillRateBusinessCategory;
use App\Services\Team\BrandAssignmentScope;
use App\Support\DataScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerBrandReportService
{
    public function __construct(
        private readonly FillRateBusinessCategory $businessCategory,
        private readonly FillRateCalculator $fillRateCalculator,
        private readonly BrandAssignmentScope $brandAssignmentScope,
        private readonly ProductBrandClassifier $brandClassifier,
    ) {}

    /**
     * @param  string|null  $category  manufactured|trading business category
     * @param  string|null  $partnerBrand  manufactured|trading (Partner Brands group key)
     * @param  string|null  $brand  specific inventory brand name
     * @param  bool  $includeSalesOrders  nested SO detail (slow) — only for single-customer detail
     */
    public function report(
        User $user,
        string $dateFrom,
        string $dateTo,
        ?string $customerId = null,
        ?string $category = null,
        ?string $segment = null,
        ?string $partnerBrand = null,
        ?string $brand = null,
        bool $includeSalesOrders = true,
    ): array {
        // Partner Brands group maps to trading product type (Kim-Fay = manufactured).
        if ($partnerBrand === 'trading' && $category === null) {
            $category = 'trading';
        } elseif ($partnerBrand === 'manufactured' && $category === null) {
            $category = 'manufactured';
        }

        $brandFilter = $brand !== null && trim($brand) !== '' ? trim($brand) : null;
        $allowedBrands = $this->brandAssignmentScope->allowedBrands($user);

        // Flat line-level query — avoids hydrating every order model + relations.
        $orderQuery = DataScope::applyOrderScope(
            AcumaticaSalesOrder::query()->salesOrdersOnly(),
            $user,
        )
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo);

        if ($customerId !== null) {
            $orderQuery->where('customer_acumatica_id', $customerId);
        }

        $orderIdsSub = (clone $orderQuery)->select('acumatica_sales_orders.id');

        $lineQuery = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->leftJoin('acumatica_inventory_items as i', 'i.inventory_id', '=', 'l.inventory_id')
            ->leftJoin('acumatica_customers as c', 'c.acumatica_id', '=', 'o.customer_acumatica_id')
            ->whereIn('o.id', $orderIdsSub)
            ->select([
                'o.id as order_id',
                'o.acumatica_order_nbr',
                'o.order_date',
                'o.status as order_status',
                'o.customer_acumatica_id',
                'o.customer_name',
                'o.workflow_reason_label',
                'o.workflow_sub_reason_code',
                'o.rejection_reason',
                'o.on_hold_reason',
                'c.name as customer_display_name',
                'c.customer_class',
                'l.inventory_id',
                'l.order_qty',
                'l.shipped_qty',
                'l.qty_on_shipments',
                'l.unit_price',
                'l.discount_amount',
                'l.unfilled_reason_code',
                'l.description as line_description',
                'i.brand',
                'i.product_type',
                'i.description as item_description',
            ]);

        // Brand / assignment filters are applied in PHP after prefix-aware resolveBrand()
        // so Aptamil (APTML*), Cow & Gate (COWGT*), Fay (FAY*), etc. still match when
        // acumatica_inventory_items.brand is null or the inventory master is empty.
        if ($brandFilter !== null) {
            $prefixes = $this->brandClassifier->prefixesForBrand($brandFilter);
            $lineQuery->where(function ($q) use ($brandFilter, $prefixes) {
                $q->whereRaw('LOWER(TRIM(COALESCE(i.brand, ""))) = ?', [strtolower($brandFilter)]);
                foreach ($prefixes as $prefix) {
                    $q->orWhere('l.inventory_id', 'like', $prefix.'%');
                }
            });
        }

        // Prefer product_type when set; nulls are refined with prefix classify in PHP.

        $lines = $lineQuery
            ->orderByDesc('o.order_date')
            ->orderByDesc('o.acumatica_order_nbr')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        // Always load backorder reasons so undelivered SKU rows can show "why at risk"
        // even when nested sales_orders detail is skipped for speed.
        $backorderReasons = collect();
        $orderNumbers = $lines->pluck('acumatica_order_nbr')->filter()->unique()->values();
        $inventoryIds = $lines->pluck('inventory_id')->filter()->unique()->values();
        if ($orderNumbers->isNotEmpty() && $inventoryIds->isNotEmpty()) {
            $backorderReasons = AcumaticaBackorderLine::query()
                ->whereIn('order_nbr', $orderNumbers)
                ->whereIn('inventory_id', $inventoryIds)
                ->get(['order_nbr', 'inventory_id', 'reason_code'])
                ->keyBy(fn ($row) => $row->order_nbr.'|'.$row->inventory_id);
        }

        $days = max(1, Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1);
        $projectionFactor = $this->projectionFactor($dateFrom, $dateTo, $days);
        $customers = [];

        foreach ($lines as $line) {
            // Category filter with prefix fallback when product_type missing/ambiguous
            $lineCategory = $this->businessCategory->classify(
                $line->inventory_id,
                $line->product_type ?: null,
            );
            if ($category !== null && $category !== $lineCategory) {
                continue;
            }

            // Segment double-check (handles edge cases not caught by SQL)
            if ($segment !== null
                && $this->fillRateCalculator->segmentForCustomerClass($line->customer_class) !== $segment) {
                continue;
            }

            $brandName = $this->brandClassifier->resolveBrand(
                $line->brand ?? null,
                $line->inventory_id ?? null,
                $line->item_description ?? $line->line_description ?? null,
            ) ?? 'Unclassified';

            if ($brandFilter !== null) {
                $want = $this->brandClassifier->normalizePartnerBrand($brandFilter)
                    ?? $this->brandClassifier->normalizeKimfayBrand($brandFilter)
                    ?? $brandFilter;
                $have = $this->brandClassifier->normalizePartnerBrand($brandName)
                    ?? $this->brandClassifier->normalizeKimfayBrand($brandName)
                    ?? $brandName;
                if (strcasecmp((string) $have, (string) $want) !== 0) {
                    continue;
                }
            }
            if ($allowedBrands !== null) {
                $allowedOk = false;
                foreach ($allowedBrands as $allowed) {
                    $a = $this->brandClassifier->normalizePartnerBrand($allowed)
                        ?? $this->brandClassifier->normalizeKimfayBrand($allowed)
                        ?? $allowed;
                    $h = $this->brandClassifier->normalizePartnerBrand($brandName)
                        ?? $this->brandClassifier->normalizeKimfayBrand($brandName)
                        ?? $brandName;
                    if (strcasecmp((string) $h, (string) $a) === 0) {
                        $allowedOk = true;
                        break;
                    }
                }
                if (! $allowedOk) {
                    continue;
                }
            }

            $id = (string) ($line->customer_acumatica_id ?? 'UNKNOWN');
            $customers[$id] ??= [
                'customer' => [
                    'id' => $id,
                    'name' => $line->customer_display_name ?? $line->customer_name ?? $id,
                    'customer_class' => $line->customer_class,
                ],
                'totals' => $this->emptyMetrics(),
                'brands' => [],
                'undelivered_reasons' => [],
                'sales_orders' => [],
                '_orders' => [],
                '_order_buckets' => [],
            ];

            $customer =& $customers[$id];
            $orderId = (int) $line->order_id;
            $customer['_orders'][$orderId] = true;

            $ordered = max(0, (float) $line->order_qty);
            $rawShipped = $line->shipped_qty;
            if ($rawShipped === null || $rawShipped === '') {
                $rawShipped = $line->qty_on_shipments;
            }
            $shipped = min($ordered, max(0, (float) $rawShipped));
            $undelivered = max(0, $ordered - $shipped);
            $netValue = max(0, ($ordered * (float) $line->unit_price) - (float) $line->discount_amount);
            $soldValue = $ordered > 0 ? $netValue * ($shipped / $ordered) : 0;
            $undeliveredValue = max(0, $netValue - $soldValue);
            $lineMetrics = [
                'so_count' => 0,
                'ordered_qty' => $ordered,
                'ordered_value' => $netValue,
                'sold_qty' => $shipped,
                'sold_value' => $soldValue,
                'undelivered_qty' => $undelivered,
                'undelivered_value' => $undeliveredValue,
            ];

            $this->addMetrics($customer['totals'], $lineMetrics);
            $customer['brands'][$brandName] ??= $this->emptyMetrics() + ['brand' => $brandName, '_orders' => [], '_skus' => []];
            $this->addMetrics($customer['brands'][$brandName], $lineMetrics);
            $customer['brands'][$brandName]['_orders'][$orderId] = true;

            $lineMeta = [
                'workflow_reason_label' => $line->workflow_reason_label,
                'workflow_sub_reason_code' => $line->workflow_sub_reason_code,
                'rejection_reason' => $line->rejection_reason,
                'on_hold_reason' => $line->on_hold_reason,
            ];

            // Track per-SKU undelivered split (customer brand + SO brand expand).
            $orderNbr = (string) ($line->acumatica_order_nbr ?? '');
            $lineDesc = trim((string) ($line->item_description ?? $line->line_description ?? ''));
            $skuId = trim((string) ($line->inventory_id ?? ''));
            if ($skuId === '' && $undelivered > 0) {
                // Fallback so expand still lists a row when inventory ID is missing.
                $skuId = $lineDesc !== '' ? 'NO-ID:'.$lineDesc : 'NO-ID:line';
            }
            $skuReason = null;
            if ($undelivered > 0 && $skuId !== '') {
                $skuReason = $this->reasonForLine(
                    $orderNbr,
                    trim((string) ($line->inventory_id ?? '')),
                    $line->unfilled_reason_code,
                    $lineMeta,
                    $backorderReasons,
                );
                $this->accumulateUndeliveredSku(
                    $customer['brands'][$brandName]['_skus'],
                    $skuId,
                    $lineDesc !== '' ? $lineDesc : $skuId,
                    (float) $line->unit_price,
                    $ordered,
                    $shipped,
                    $undelivered,
                    $undeliveredValue,
                    $orderId,
                    $orderNbr,
                    $skuReason,
                );
            }

            if ($includeSalesOrders) {
                $customer['_order_buckets'][$orderId] ??= [
                    'id' => $orderId,
                    'order_nbr' => $line->acumatica_order_nbr,
                    'order_date' => $line->order_date
                        ? Carbon::parse($line->order_date)->toDateString()
                        : null,
                    'status' => $line->order_status,
                    'metrics' => $this->emptyMetrics(),
                    'brands' => [],
                    'reasons' => [],
                    'meta' => $lineMeta,
                ];
                $bucket =& $customer['_order_buckets'][$orderId];
                $this->addMetrics($bucket['metrics'], $lineMetrics);
                $bucket['brands'][$brandName] ??= $this->emptyMetrics() + [
                    'brand' => $brandName,
                    '_skus' => [],
                ];
                $this->addMetrics($bucket['brands'][$brandName], $lineMetrics);

                // Same inventory-ID split under this SO brand (so expand shows SKUs).
                if ($undelivered > 0 && $skuId !== '') {
                    $this->accumulateUndeliveredSku(
                        $bucket['brands'][$brandName]['_skus'],
                        $skuId,
                        $lineDesc !== '' ? $lineDesc : $skuId,
                        (float) $line->unit_price,
                        $ordered,
                        $shipped,
                        $undelivered,
                        $undeliveredValue,
                        $orderId,
                        $orderNbr,
                        $skuReason ?? $this->reasonForLine(
                            $orderNbr,
                            trim((string) ($line->inventory_id ?? '')),
                            $line->unfilled_reason_code,
                            $bucket['meta'],
                            $backorderReasons,
                        ),
                    );
                }

                if ($undelivered > 0) {
                    $reason = $skuReason ?? $this->reasonForLine(
                        $orderNbr,
                        $skuId,
                        $line->unfilled_reason_code,
                        $bucket['meta'],
                        $backorderReasons,
                    );
                    $customer['undelivered_reasons'][$reason] ??= [
                        'reason' => $reason,
                        'quantity' => 0,
                        'value' => 0,
                        '_orders' => [],
                    ];
                    $customer['undelivered_reasons'][$reason]['quantity'] += $undelivered;
                    $customer['undelivered_reasons'][$reason]['value'] += $undeliveredValue;
                    $customer['undelivered_reasons'][$reason]['_orders'][$orderId] = true;
                    $bucket['reasons'][$reason] = true;
                }
                unset($bucket);
            }

            unset($customer);
        }

        $rows = collect($customers)->filter(fn (array $customer) => count($customer['_orders']) > 0)->map(function (array $customer) use ($days, $projectionFactor, $includeSalesOrders) {
            $customer['totals']['so_count'] = count($customer['_orders']);
            $customer['totals'] = $this->finishMetrics($customer['totals'], $days, $projectionFactor);
            $customer['brands'] = collect($customer['brands'])->map(function (array $brand) use ($days, $projectionFactor) {
                $brand['so_count'] = count($brand['_orders']);
                $brand['undelivered_skus'] = $this->finishSkuList($brand['_skus'] ?? []);
                $brand['undelivered_sku_count'] = count($brand['undelivered_skus']);
                unset($brand['_orders'], $brand['_skus']);

                return $this->finishMetrics($brand, $days, $projectionFactor);
            })->sortByDesc('sold_value')->values()->all();

            if ($includeSalesOrders) {
                $customer['undelivered_reasons'] = collect($customer['undelivered_reasons'])->map(function (array $reason) {
                    $reason['so_count'] = count($reason['_orders']);
                    unset($reason['_orders']);
                    $reason['quantity'] = round($reason['quantity'], 4);
                    $reason['value'] = round($reason['value'], 2);

                    return $reason;
                })->sortByDesc('value')->values()->all();

                $customer['sales_orders'] = collect($customer['_order_buckets'] ?? [])
                    ->filter(fn (array $bucket) => ($bucket['metrics']['ordered_qty'] ?? 0) > 0
                        || ($bucket['metrics']['ordered_value'] ?? 0) > 0)
                    ->map(function (array $bucket) use ($days, $projectionFactor) {
                        $metrics = $this->finishMetrics($bucket['metrics'], $days, $projectionFactor);
                        $metrics['so_count'] = 1;

                        return $metrics + [
                            'id' => $bucket['id'],
                            'order_nbr' => $bucket['order_nbr'],
                            'order_date' => $bucket['order_date'],
                            'status' => $bucket['status'],
                            'brands' => collect($bucket['brands'])->map(function (array $brand) use ($days, $projectionFactor) {
                                $brand['so_count'] = 1;
                                $brand['undelivered_skus'] = $this->finishSkuList($brand['_skus'] ?? []);
                                $brand['undelivered_sku_count'] = count($brand['undelivered_skus']);
                                unset($brand['_skus']);

                                return $this->finishMetrics($brand, $days, $projectionFactor);
                            })->sortByDesc('sold_value')->values()->all(),
                            'reasons' => array_keys($bucket['reasons'] ?? []),
                        ];
                    })
                    ->sortByDesc('order_date')
                    ->values()
                    ->all();
            } else {
                $customer['undelivered_reasons'] = [];
                $customer['sales_orders'] = [];
            }

            unset($customer['_orders'], $customer['_order_buckets']);

            return $customer;
        })->sortBy(fn ($customer) => strtolower($customer['customer']['name']))->values();

        return $rows->all();
    }

    /**
     * Roll up customer rows into a brand-first summary for Partner Brand teams.
     *
     * @param  list<array<string, mixed>>  $customerRows
     * @return list<array<string, mixed>>
     */
    public function brandRollup(array $customerRows): array
    {
        $byBrand = [];
        foreach ($customerRows as $customer) {
            foreach ($customer['brands'] ?? [] as $brandRow) {
                $name = (string) ($brandRow['brand'] ?? 'Unclassified');
                $byBrand[$name] ??= $this->emptyMetrics() + [
                    'brand' => $name,
                    'customer_count' => 0,
                    '_customers' => [],
                    '_skus' => [],
                ];
                $this->addMetrics($byBrand[$name], [
                    'ordered_qty' => $brandRow['ordered_qty'] ?? 0,
                    'ordered_value' => $brandRow['ordered_value'] ?? 0,
                    'sold_qty' => $brandRow['sold_qty'] ?? 0,
                    'sold_value' => $brandRow['sold_value'] ?? 0,
                    'undelivered_qty' => $brandRow['undelivered_qty'] ?? 0,
                    'undelivered_value' => $brandRow['undelivered_value'] ?? 0,
                ]);
                $byBrand[$name]['so_count'] += (int) ($brandRow['so_count'] ?? 0);
                $custId = (string) ($customer['customer']['id'] ?? '');
                if ($custId !== '') {
                    $byBrand[$name]['_customers'][$custId] = true;
                }
                // Merge undelivered SKU split across customers.
                foreach ($brandRow['undelivered_skus'] ?? [] as $skuRow) {
                    $skuId = (string) ($skuRow['inventory_id'] ?? '');
                    if ($skuId === '') {
                        continue;
                    }
                    $sku =& $byBrand[$name]['_skus'][$skuId];
                    if (! isset($sku)) {
                        $sku = [
                            'inventory_id' => $skuId,
                            'description' => (string) ($skuRow['description'] ?? $skuId),
                            'unit_price' => (float) ($skuRow['unit_price'] ?? 0),
                            'ordered_qty' => 0.0,
                            'sold_qty' => 0.0,
                            'undelivered_qty' => 0.0,
                            'undelivered_value' => 0.0,
                            'so_count' => 0,
                            '_reasons' => [],
                            '_order_nbrs' => [],
                        ];
                    }
                    $sku['ordered_qty'] += (float) ($skuRow['ordered_qty'] ?? 0);
                    $sku['sold_qty'] += (float) ($skuRow['sold_qty'] ?? 0);
                    $sku['undelivered_qty'] += (float) ($skuRow['undelivered_qty'] ?? 0);
                    $sku['undelivered_value'] += (float) ($skuRow['undelivered_value'] ?? 0);
                    $sku['so_count'] += (int) ($skuRow['so_count'] ?? 0);
                    if ((float) ($skuRow['unit_price'] ?? 0) > 0) {
                        $sku['unit_price'] = (float) $skuRow['unit_price'];
                    }
                    $desc = trim((string) ($skuRow['description'] ?? ''));
                    if ($desc !== '' && $desc !== $skuId) {
                        $sku['description'] = $desc;
                    }
                    foreach ($skuRow['reasons'] ?? [] as $reasonLabel) {
                        $label = trim((string) $reasonLabel);
                        if ($label !== '') {
                            $sku['_reasons'][$label] = true;
                        }
                    }
                    foreach ($skuRow['order_nbrs'] ?? [] as $nbr) {
                        $nbrStr = trim((string) $nbr);
                        if ($nbrStr !== '') {
                            $sku['_order_nbrs'][$nbrStr] = true;
                        }
                    }
                    unset($sku);
                }
            }
        }

        return collect($byBrand)->map(function (array $row) {
            $row['customer_count'] = count($row['_customers'] ?? []);
            $row['undelivered_skus'] = $this->finishSkuList($row['_skus'] ?? []);
            $row['undelivered_sku_count'] = count($row['undelivered_skus']);
            unset($row['_customers'], $row['_skus']);
            $row['fill_rate_pct'] = $row['ordered_qty'] > 0
                ? round(($row['sold_qty'] / $row['ordered_qty']) * 100, 2)
                : null;
            foreach (['ordered_qty', 'sold_qty', 'undelivered_qty'] as $key) {
                $row[$key] = round((float) $row[$key], 4);
            }
            foreach (['ordered_value', 'sold_value', 'undelivered_value'] as $key) {
                $row[$key] = round((float) $row[$key], 2);
            }

            return $row;
        })->sortByDesc('sold_value')->values()->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $skus
     * @return list<array<string, mixed>>
     */
    /**
     * @param  array<string, array<string, mixed>>  $skus
     */
    private function accumulateUndeliveredSku(
        array &$skus,
        string $skuId,
        string $description,
        float $unitPrice,
        float $ordered,
        float $shipped,
        float $undelivered,
        float $undeliveredValue,
        int $orderId,
        string $orderNbr,
        string $reason,
    ): void {
        if (! isset($skus[$skuId])) {
            $skus[$skuId] = [
                'inventory_id' => $skuId,
                'description' => $description,
                'unit_price' => $unitPrice,
                'ordered_qty' => 0.0,
                'sold_qty' => 0.0,
                'undelivered_qty' => 0.0,
                'undelivered_value' => 0.0,
                '_orders' => [],
                '_order_nbrs' => [],
                '_reasons' => [],
            ];
        }
        $sku =& $skus[$skuId];
        $sku['ordered_qty'] += $ordered;
        $sku['sold_qty'] += $shipped;
        $sku['undelivered_qty'] += $undelivered;
        $sku['undelivered_value'] += $undeliveredValue;
        if ($unitPrice > 0) {
            $sku['unit_price'] = $unitPrice;
        }
        if ($description !== '' && $description !== $skuId) {
            $sku['description'] = $description;
        }
        $sku['_orders'][$orderId] = true;
        if ($orderNbr !== '') {
            $sku['_order_nbrs'][$orderNbr] = true;
        }
        if (trim($reason) !== '') {
            $sku['_reasons'][$reason] = true;
        }
        unset($sku);
    }

    private function finishSkuList(array $skus): array
    {
        return collect($skus)
            ->map(function (array $sku) {
                if (isset($sku['_order_nbrs']) && is_array($sku['_order_nbrs'])) {
                    $orderNbrs = array_keys($sku['_order_nbrs']);
                } elseif (isset($sku['order_nbrs']) && is_array($sku['order_nbrs'])) {
                    $orderNbrs = $sku['order_nbrs'];
                } else {
                    $orderNbrs = [];
                }
                $orderNbrs = array_values(array_unique(array_filter(array_map(
                    fn ($n) => trim((string) $n),
                    $orderNbrs,
                ), fn ($n) => $n !== '')));
                sort($orderNbrs);

                if (isset($sku['_reasons']) && is_array($sku['_reasons'])) {
                    $reasons = array_keys($sku['_reasons']);
                } elseif (isset($sku['reasons']) && is_array($sku['reasons'])) {
                    $reasons = $sku['reasons'];
                } else {
                    $reasons = [];
                }
                $reasons = array_values(array_unique(array_filter(array_map(
                    fn ($r) => trim((string) $r),
                    $reasons,
                ), fn ($r) => $r !== '')));
                sort($reasons);

                $sku['so_count'] = isset($sku['_orders'])
                    ? count($sku['_orders'])
                    : (count($orderNbrs) > 0
                        ? count($orderNbrs)
                        : (int) ($sku['so_count'] ?? 0));
                $sku['order_nbrs'] = $orderNbrs;
                $sku['reasons'] = $reasons;
                unset($sku['_orders'], $sku['_order_nbrs'], $sku['_reasons']);
                $sku['ordered_qty'] = round((float) $sku['ordered_qty'], 4);
                $sku['sold_qty'] = round((float) $sku['sold_qty'], 4);
                $sku['undelivered_qty'] = round((float) $sku['undelivered_qty'], 4);
                $sku['undelivered_value'] = round((float) $sku['undelivered_value'], 2);
                $sku['unit_price'] = round((float) $sku['unit_price'], 2);

                return $sku;
            })
            ->filter(fn (array $sku) => ($sku['undelivered_qty'] ?? 0) > 0)
            ->sortByDesc('undelivered_qty')
            ->values()
            ->all();
    }

    /**
     * Portfolio totals across all customer rows after filters.
     *
     * @param  list<array<string, mixed>>  $customerRows
     * @return array<string, mixed>
     */
    public function portfolioTotals(array $customerRows): array
    {
        $totals = $this->emptyMetrics();
        $customers = 0;
        $brands = [];
        foreach ($customerRows as $customer) {
            if (($customer['totals'] ?? null) === null) {
                continue;
            }
            $customers++;
            $this->addMetrics($totals, $customer['totals']);
            $totals['so_count'] += (int) ($customer['totals']['so_count'] ?? 0);
            foreach ($customer['brands'] ?? [] as $brandRow) {
                $brands[(string) ($brandRow['brand'] ?? '')] = true;
            }
        }
        $totals['fill_rate_pct'] = $totals['ordered_qty'] > 0
            ? round(($totals['sold_qty'] / $totals['ordered_qty']) * 100, 2)
            : null;
        foreach (['ordered_qty', 'sold_qty', 'undelivered_qty'] as $key) {
            $totals[$key] = round((float) $totals[$key], 4);
        }
        foreach (['ordered_value', 'sold_value', 'undelivered_value'] as $key) {
            $totals[$key] = round((float) $totals[$key], 2);
        }

        return $totals + [
            'customer_count' => $customers,
            'brand_count' => count(array_filter(array_keys($brands))),
        ];
    }

    private function emptyMetrics(): array
    {
        return ['so_count' => 0, 'ordered_qty' => 0, 'ordered_value' => 0, 'sold_qty' => 0, 'sold_value' => 0, 'undelivered_qty' => 0, 'undelivered_value' => 0];
    }

    private function addMetrics(array &$target, array $source): void
    {
        foreach (['ordered_qty', 'ordered_value', 'sold_qty', 'sold_value', 'undelivered_qty', 'undelivered_value'] as $key) {
            $target[$key] += $source[$key];
        }
    }

    private function finishMetrics(array $metrics, int $days, float $projectionFactor): array
    {
        foreach (['ordered_qty', 'sold_qty', 'undelivered_qty'] as $key) {
            $metrics[$key] = round($metrics[$key], 4);
        }
        foreach (['ordered_value', 'sold_value', 'undelivered_value'] as $key) {
            $metrics[$key] = round($metrics[$key], 2);
        }
        $metrics['fill_rate_pct'] = $metrics['ordered_qty'] > 0 ? round(($metrics['sold_qty'] / $metrics['ordered_qty']) * 100, 2) : null;
        $metrics['avg_daily_sold_qty'] = round($metrics['sold_qty'] / $days, 4);
        $metrics['avg_daily_sold_value'] = round($metrics['sold_value'] / $days, 2);
        $metrics['projected_month_end_qty'] = round($metrics['sold_qty'] * $projectionFactor, 4);
        $metrics['projected_month_end_value'] = round($metrics['sold_value'] * $projectionFactor, 2);

        return $metrics;
    }

    private function projectionFactor(string $from, string $to, int $days): float
    {
        $start = Carbon::parse($from, 'Africa/Nairobi');
        $end = Carbon::parse($to, 'Africa/Nairobi');
        $today = Carbon::now('Africa/Nairobi')->startOfDay();
        if ($start->isSameMonth($today) && $end->isSameMonth($today)) {
            return $today->daysInMonth / max(1, min($days, $today->day));
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $orderMeta
     * @param  Collection<string, mixed>  $backorders
     */
    private function reasonForLine(
        string $orderNbr,
        string $inventoryId,
        mixed $unfilledReason,
        array $orderMeta,
        Collection $backorders,
    ): string {
        $values = [
            $unfilledReason,
            $backorders->get($orderNbr.'|'.$inventoryId)?->reason_code,
            $orderMeta['workflow_reason_label'] ?? null,
            $orderMeta['workflow_sub_reason_code'] ?? null,
            $orderMeta['rejection_reason'] ?? null,
            $orderMeta['on_hold_reason'] ?? null,
        ];
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'Unspecified';
    }
}
