<?php

namespace App\Services\Admin;

use App\Services\Operations\SalesOrderReasonCatalog;

class SalesOrderLineFulfillmentDeriver
{
    public const UNFILLED_REASON_OUT_OF_STOCK = 'out_of_stock_procurement';

    public const UNFILLED_REASON_PARTIAL_SHORTAGE = 'out_of_stock_procurement';

    public const STATUS_FULLY_FULFILLED = 'Fully Fulfilled';

    public const STATUS_BACKORDERS_IMPORTED = 'Backorders Imported';

    public const STATUS_CANCELLED = 'Cancelled';

    public const STATUS_PARTIALLY_SHIPPED = 'Partially Shipped — Backorder Pending';

    public const STATUS_PENDING_SHIPMENT = 'Pending Shipment';

    /** @var list<string> */
    public const BACKORDER_STATUSES = [
        self::STATUS_BACKORDERS_IMPORTED,
        self::STATUS_PARTIALLY_SHIPPED,
    ];

    /**
     * @param  array<string, mixed>  $lineRaw
     * @return array{
     *   line_nbr: int,
     *   inventory_id: ?string,
     *   description: ?string,
     *   order_qty: float,
     *   shipped_qty: float,
     *   qty_on_shipments: float,
     *   qty_on_shipments_source: string,
     *   open_qty: float,
     *   cancelled_qty: float,
     *   qty_at_approval: float,
     *   backorder_qty: float,
     *   fill_rate_pct: ?float,
     *   unfilled_reason_code: ?string,
     *   line_type: ?string,
     *   completed: bool,
     *   fulfillment_status: string,
     *   warehouse_id: ?string,
     *   uom: ?string,
     *   unit_price: float,
     *   ext_cost: float,
     *   discount_amount: float,
     *   discount_code: ?string,
     *   reason_code: ?string,
     *   reason_notes: ?string,
     * }
     */
    public static function mapFromRaw(array $lineRaw): array
    {
        $orderQty = self::floatVal($lineRaw['OrderQty'] ?? $lineRaw['OrderedQty'] ?? null);
        // IpayV2 often omits ShippedQty; QtyOnShipments is the reliable delivered qty.
        $shippedQtyExplicit = self::hasField($lineRaw, 'ShippedQty')
            ? self::floatVal($lineRaw['ShippedQty'] ?? null)
            : null;
        $cancelledQty = self::floatVal($lineRaw['CancelledQty'] ?? null);

        [$qtyOnShipments, $qtyOnShipmentsSource] = self::resolveQtyOnShipments(
            $lineRaw,
            $shippedQtyExplicit ?? 0.0,
        );

        // Prefer explicit ShippedQty when present; otherwise treat QtyOnShipments as delivered.
        $shippedQty = $shippedQtyExplicit !== null && $shippedQtyExplicit > 0
            ? $shippedQtyExplicit
            : ($qtyOnShipments > 0 ? $qtyOnShipments : ($shippedQtyExplicit ?? 0.0));

        $openQty = self::resolveOpenQty($lineRaw, $orderQty, $shippedQty, $cancelledQty);
        $qtyAtApproval = self::floatVal($lineRaw['UsrQtyAtApproval'] ?? null);
        if ($qtyAtApproval <= 0) {
            $qtyAtApproval = $orderQty;
        }

        // Fill rate demand = Order Qty (not qty-at-approval).
        $demandQty = $orderQty > 0 ? $orderQty : $qtyAtApproval;
        $shippedForFill = $shippedQty > 0 ? $shippedQty : $qtyOnShipments;
        $reasonCode = self::strVal($lineRaw['ReasonCode'] ?? null);

        $completed = self::boolVal($lineRaw['Completed'] ?? null);
        $fulfillmentStatus = self::deriveLineStatus($orderQty, $shippedQty, $openQty, $cancelledQty, $completed);

        $unitPrice = self::resolveUnitPrice($lineRaw, $orderQty);
        $extCost = self::resolveExtCost($lineRaw);

        return [
            'line_nbr'            => (int) (self::strVal($lineRaw['LineNbr'] ?? null) ?? 0),
            'inventory_id'        => self::strVal($lineRaw['InventoryID'] ?? null),
            'description'         => self::strVal($lineRaw['TransactionDescr'] ?? $lineRaw['Description'] ?? $lineRaw['TranDesc'] ?? $lineRaw['LineDescription'] ?? null),
            'order_qty'           => $orderQty,
            'shipped_qty'         => $shippedQty,
            'qty_on_shipments'    => $qtyOnShipments,
            'qty_on_shipments_source' => $qtyOnShipmentsSource,
            'open_qty'            => $openQty,
            'cancelled_qty'       => $cancelledQty,
            'qty_at_approval'     => $qtyAtApproval,
            // Missing / open qty only — NOT (order − shipped) which can equal full invoice qty
            // when cancelled/closed qty is omitted and OpenQty is the true remainder.
            'backorder_qty'       => self::backorderQty($demandQty, $shippedForFill, $openQty, $cancelledQty),
            'fill_rate_pct'       => self::safeFillRate($shippedForFill, $demandQty),
            'unfilled_reason_code' => self::deriveUnfilledReasonCode($shippedForFill, $demandQty, $reasonCode),
            'line_type'           => self::strVal($lineRaw['LineType'] ?? null),
            'completed'           => $completed,
            'fulfillment_status'  => $fulfillmentStatus,
            'warehouse_id'        => self::strVal($lineRaw['WarehouseID'] ?? $lineRaw['SiteID'] ?? null),
            'uom'                 => self::strVal($lineRaw['UOM'] ?? null),
            'unit_price'          => $unitPrice,
            'ext_cost'            => $extCost,
            'discount_amount'     => self::floatVal($lineRaw['DiscountAmt'] ?? $lineRaw['DiscountAmount'] ?? null),
            'discount_code'       => self::strVal($lineRaw['DiscountCode'] ?? null),
            'reason_code'         => self::strVal($lineRaw['ReasonCode'] ?? null),
            'reason_notes'        => self::firstString($lineRaw, [
                'ReasonDescription',
                'ReasonCodeDescription',
                'BackorderReason',
                'BackorderReasonDescription',
                'ReasonNote',
                'UsrReasonNotes',
                'Note',
            ]),
        ];
    }

    public static function deriveLineStatus(
        float $orderQty,
        float $shippedQty,
        float $openQty,
        float $cancelledQty,
        bool $completed,
    ): string {
        // Fully delivered: completed flag, open cleared, or shipped covers order.
        if ($completed && ($openQty <= 0 || $shippedQty >= $orderQty)) {
            return self::STATUS_FULLY_FULFILLED;
        }

        if ($openQty <= 0 && $shippedQty >= $orderQty && $orderQty > 0) {
            return self::STATUS_FULLY_FULFILLED;
        }

        if ($openQty <= 0 && $completed) {
            return self::STATUS_FULLY_FULFILLED;
        }

        if ($openQty > 0 && $shippedQty < $orderQty) {
            return self::STATUS_BACKORDERS_IMPORTED;
        }

        if ($cancelledQty > 0 && $shippedQty == 0.0) {
            return self::STATUS_CANCELLED;
        }

        if ($shippedQty > 0 && $openQty > 0) {
            return self::STATUS_PARTIALLY_SHIPPED;
        }

        return self::STATUS_PENDING_SHIPMENT;
    }

    /**
     * Qty still open / at risk (missing item qty), not full ordered qty.
     *
     * Prefer Acumatica OpenQty when present. Fall back to
     * order − shipped − cancelled. Never treat full order qty as backorder
     * when OpenQty is a smaller remainder (cancelled/closed without ship).
     */
    public static function backorderQty(
        float $demandQty,
        float $qtyOnShipments,
        float $openQty = 0.0,
        float $cancelledQty = 0.0,
    ): float {
        if ($openQty > 0) {
            return $openQty;
        }

        $derived = max($demandQty - $qtyOnShipments - max($cancelledQty, 0.0), 0.0);
        if ($derived > 0) {
            return $derived;
        }

        return max($demandQty - $qtyOnShipments, 0.0);
    }

    /**
     * Unit price per UOM — never use ExtendedPrice/Amount (line/invoice totals).
     * Prefer document-currency fields when present.
     *
     * @param  array<string, mixed>  $lineRaw
     */
    public static function resolveUnitPrice(array $lineRaw, float $orderQty = 0.0): float
    {
        $unitPrice = self::floatVal(
            $lineRaw['CuryUnitPrice']
                ?? $lineRaw['UnitPrice']
                ?? $lineRaw['DiscountedUnitPrice']
                ?? null,
        );

        if ($unitPrice > 0) {
            return $unitPrice;
        }

        // Last resort: derive from extended / order qty (never use order total).
        $ext = self::resolveExtCost($lineRaw);
        if ($ext > 0 && $orderQty > 0) {
            return round($ext / $orderQty, 4);
        }

        return 0.0;
    }

    /**
     * Line extended amount (for reference). Prefer ExtendedPrice / CuryExtPrice.
     *
     * @param  array<string, mixed>  $lineRaw
     */
    public static function resolveExtCost(array $lineRaw): float
    {
        return self::floatVal(
            $lineRaw['CuryExtPrice']
                ?? $lineRaw['ExtendedPrice']
                ?? $lineRaw['ExtPrice']
                ?? $lineRaw['ExtCost']
                ?? $lineRaw['Amount']
                ?? null,
        );
    }

    /**
     * Monetary value of open/missing qty: open_qty × unit_price.
     * Never order_total / invoice total.
     */
    public static function openLineValue(float $openOrBackorderQty, float $unitPrice): float
    {
        if ($openOrBackorderQty <= 0 || $unitPrice <= 0) {
            return 0.0;
        }

        return round($openOrBackorderQty * $unitPrice, 2);
    }

    /**
     * Residual open qty for backorder value cards (matches Acumatica OpenQty /
     * Excel Unbilled line basis).
     *
     * Prefer stored OpenQty — it is already net of shipments. Never subtract
     * QtyOnShipments again from that residual (that double-count deflated the
     * dashboard Backorder value vs Current outstanding).
     *
     * Delivered qty prefers ShippedQty, then QtyOnShipments (IpayV2 often only
     * populates the latter).
     */
    public static function residualOpenQty(
        float $orderQty,
        float $shippedQty,
        float $qtyOnShipments = 0.0,
        float $cancelledQty = 0.0,
        ?float $storedOpenQty = null,
    ): float {
        if ($storedOpenQty !== null && $storedOpenQty > 0) {
            return $storedOpenQty;
        }

        $delivered = self::deliveredQty($shippedQty, $qtyOnShipments);
        $netOrderQty = max(0.0, $orderQty - max(0.0, $cancelledQty));

        return max(0.0, $netOrderQty - min($delivered, $netOrderQty));
    }

    /**
     * Qty treated as shipped/delivered for invoiced value on backorder lines.
     */
    public static function deliveredQty(float $shippedQty, float $qtyOnShipments = 0.0): float
    {
        $shipped = max(0.0, $shippedQty);
        if ($shipped > 0) {
            return $shipped;
        }

        return max(0.0, $qtyOnShipments);
    }

    /**
     * QtyOnShipments is the per-item fill-rate numerator. When Acumatica omits it,
     * fall back to ShippedQty so legacy payloads still compute.
     *
     * @return array{0: float, 1: string}
     */
    public static function resolveQtyOnShipments(array $lineRaw, float $shippedQty): array
    {
        if (self::hasField($lineRaw, 'QtyOnShipments')) {
            return [self::floatVal($lineRaw['QtyOnShipments']), 'qty_on_shipments'];
        }

        return [$shippedQty, 'shipped_qty_fallback'];
    }

    public static function deriveUnfilledReasonCode(
        float $qtyOnShipments,
        float $demandQty,
        ?string $acumaticaReasonCode,
    ): ?string {
        if ($demandQty <= 0) {
            return null;
        }

        if ($qtyOnShipments >= $demandQty) {
            return null;
        }

        if ($acumaticaReasonCode !== null && trim($acumaticaReasonCode) !== '') {
            return self::normalizeReasonCode($acumaticaReasonCode);
        }

        if ($qtyOnShipments <= 0) {
            return self::UNFILLED_REASON_OUT_OF_STOCK;
        }

        return self::UNFILLED_REASON_PARTIAL_SHORTAGE;
    }

    public static function normalizeReasonCode(string $code): string
    {
        $catalog = new SalesOrderReasonCatalog();
        $resolved = $catalog->resolveSubReason($code);

        return $resolved ?? strtolower(str_replace([' ', '-', '/'], '_', trim($code)));
    }

    public static function safeFillRate(float $shippedQty, float $approvedQty): ?float
    {
        if ($approvedQty <= 0) {
            return null;
        }

        $rate = ($shippedQty / $approvedQty) * 100;

        if ($rate > 100) {
            return 100.0;
        }

        return round($rate, 2);
    }

    public static function isBackorderLine(string $fulfillmentStatus, float $openQty, float $backorderQty = 0): bool
    {
        $effectiveOpen = $openQty > 0 ? $openQty : $backorderQty;
        if ($effectiveOpen <= 0) {
            return false;
        }

        if (in_array($fulfillmentStatus, [self::STATUS_FULLY_FULFILLED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        return in_array($fulfillmentStatus, self::BACKORDER_STATUSES, true)
            || $backorderQty > 0
            || ($openQty > 0 && $fulfillmentStatus === self::STATUS_PENDING_SHIPMENT);
    }

    /**
     * Prefer Acumatica OpenQty when the field is present — including explicit 0
     * (fully shipped / closed). Only derive order − shipped − cancelled when
     * OpenQty is omitted (common on some IpayV2 payloads).
     *
     * Bug this fixes: OpenQty=0 + QtyOnShipments=order was treated as open=order
     * because `if ($openQty > 0)` skipped the zero and fell back with ShippedQty=0.
     */
    public static function resolveOpenQty(
        array $lineRaw,
        float $orderQty,
        float $shippedQty,
        float $cancelledQty,
    ): float {
        if (self::hasField($lineRaw, 'OpenQty') || self::hasField($lineRaw, 'OpenLineQty')) {
            return self::floatVal($lineRaw['OpenQty'] ?? $lineRaw['OpenLineQty'] ?? null);
        }

        if ($orderQty <= 0) {
            return 0.0;
        }

        return max($orderQty - $shippedQty - $cancelledQty, 0);
    }

    private static function strVal(mixed $field): ?string
    {
        $v = AcumaticaClient::val($field);
        if ($v === null || $v === '') {
            return null;
        }
        if (is_array($v)) {
            return null;
        }

        return (string) $v;
    }

    /** @param array<string, mixed> $raw */
    private static function firstString(array $raw, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = self::strVal($raw[$field] ?? null);
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function floatVal(mixed $field): float
    {
        $v = self::strVal($field);

        return $v === null ? 0.0 : (float) $v;
    }

    private static function boolVal(mixed $field): bool
    {
        $v = AcumaticaClient::val($field);
        if (is_bool($v)) {
            return $v;
        }

        if (is_string($v)) {
            return in_array(strtolower($v), ['true', '1', 'yes'], true);
        }

        return (bool) $v;
    }

    /** @param array<string, mixed> $raw */
    private static function hasField(array $raw, string $key): bool
    {
        if (! array_key_exists($key, $raw)) {
            return false;
        }

        $value = $raw[$key];

        return ! (is_array($value) && $value === []);
    }
}
