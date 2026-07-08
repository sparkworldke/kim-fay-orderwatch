<?php

namespace App\Services\Admin;

class SalesOrderLineFulfillmentDeriver
{
    public const UNFILLED_REASON_OUT_OF_STOCK = 'inventory_shortage';

    public const UNFILLED_REASON_PARTIAL_SHORTAGE = 'inventory_shortage';

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
        $shippedQty = self::floatVal($lineRaw['ShippedQty'] ?? null);
        $cancelledQty = self::floatVal($lineRaw['CancelledQty'] ?? null);
        $openQty = self::resolveOpenQty($lineRaw, $orderQty, $shippedQty, $cancelledQty);
        $qtyAtApproval = self::floatVal($lineRaw['UsrQtyAtApproval'] ?? null);
        if ($qtyAtApproval <= 0) {
            $qtyAtApproval = $orderQty;
        }

        [$qtyOnShipments, $qtyOnShipmentsSource] = self::resolveQtyOnShipments($lineRaw, $shippedQty);
        $demandQty = $qtyAtApproval > 0 ? $qtyAtApproval : $orderQty;
        $reasonCode = self::strVal($lineRaw['ReasonCode'] ?? null);

        $completed = self::boolVal($lineRaw['Completed'] ?? null);
        $fulfillmentStatus = self::deriveLineStatus($orderQty, $shippedQty, $openQty, $cancelledQty, $completed);

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
            'backorder_qty'       => self::backorderQty($demandQty, $qtyOnShipments),
            'fill_rate_pct'       => self::safeFillRate($qtyOnShipments, $demandQty),
            'unfilled_reason_code' => self::deriveUnfilledReasonCode($qtyOnShipments, $demandQty, $reasonCode),
            'line_type'           => self::strVal($lineRaw['LineType'] ?? null),
            'completed'           => $completed,
            'fulfillment_status'  => $fulfillmentStatus,
            'warehouse_id'        => self::strVal($lineRaw['WarehouseID'] ?? $lineRaw['SiteID'] ?? null),
            'uom'                 => self::strVal($lineRaw['UOM'] ?? null),
            'unit_price'          => self::floatVal($lineRaw['UnitPrice'] ?? null),
            'ext_cost'            => self::floatVal($lineRaw['ExtPrice'] ?? $lineRaw['ExtCost'] ?? $lineRaw['Amount'] ?? null),
            'discount_amount'     => self::floatVal($lineRaw['DiscountAmt'] ?? null),
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
        if ($completed && $shippedQty >= $orderQty) {
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

    public static function backorderQty(float $demandQty, float $qtyOnShipments): float
    {
        return max($demandQty - $qtyOnShipments, 0);
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
        return strtolower(str_replace([' ', '-'], '_', trim($code)));
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
     * IpayV2 Details often omit OpenQty — derive from order/shipped/cancelled quantities.
     */
    public static function resolveOpenQty(
        array $lineRaw,
        float $orderQty,
        float $shippedQty,
        float $cancelledQty,
    ): float {
        $openQty = self::floatVal($lineRaw['OpenQty'] ?? $lineRaw['OpenLineQty'] ?? null);
        if ($openQty > 0) {
            return $openQty;
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
