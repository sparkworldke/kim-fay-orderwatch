<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaSalesOrder extends Model
{
    public const TYPE_SALES_ORDER = 'SO';
    public const TYPE_QUOTE = 'QT';
    public const TYPE_CREDIT_NOTE = 'RC';
    public const TYPE_CREDIT_MEMO = 'CM';
    public const TYPE_PICK_LIST = 'PL';

    /** Dashboard, Orders, Fill Rate, Order Match — SO only. */
    public const IN_SCOPE_ORDER_TYPES = [self::TYPE_SALES_ORDER];

    /** Credit Notes & More menu — non-SO sales documents. */
    public const CREDIT_NOTES_AND_MORE_TYPES = [
        self::TYPE_QUOTE,
        self::TYPE_CREDIT_NOTE,
        self::TYPE_CREDIT_MEMO,
        self::TYPE_PICK_LIST,
    ];

    /** @deprecated Use SalesOrderReasonCatalog::SUB_REASONS for the approved taxonomy. */
    public const REJECTION_REASON_CODES = [
        'out_of_stock_procurement',
        'out_of_stock_production',
        'delay_in_delivery',
        'promo_product',
        'transfer_delays',
        'short_expiry',
        'out_of_stock_msa',
        'raw_material_stockout',
        'discontinued',
        'pb_discontinued',
        'delayed_communication',
        'truck_full',
        'price_difference',
        'invoicing_error',
        'stock_variance',
        'isolation_error',
        'non_focus',
        'wrong_moq',
        'order_to_make',
        'kebs_stickers',
        'wrong_product_description',
        'system_error',
        'conversion_delays',
        'wrong_code',
        'price_variance',
        'delayed_supplier_payment',
        'lpo_error',
        'batch_sequence',
        'conversion_issues',
        'price_overcharge',
        'npd',
        'did_not_pick_on_shipment',
        'production_stockout',
    ];

    /** @var list<string> */
    protected $appends = ['description'];

    protected $fillable = [
        'acumatica_order_nbr',
        'order_type',
        'customer_acumatica_id',
        'customer_name',
        'customer_order',
        'location_id',
        'status',
        'order_date',
        'last_modified_at',
        'ship_date',
        'requested_on',
        'order_total',
        'currency_id',
        'sales_consultant_rep_code',
        'sales_consultant_name',
        'consultant_user_id',
        'import_source',
        'sync_run_id',
        'raw_payload',
        'synced_at',
        'approved_at',
        'approved_by_id',
        'shipped_at',
        'completed_at',
        'match_status',
        'flag_source',
        'rejection_reason',
        'rejection_reason_code',
        'on_hold_reason',
        'workflow_parent_reason',
        'workflow_sub_reason_code',
        'workflow_reason_label',
        'email_subject',
        'email_received_at',
    ];

    protected function casts(): array
    {
        return [
            'email_received_at' => 'datetime',
            'order_date'        => 'datetime',
            'ship_date'         => 'datetime',
            'requested_on'      => 'datetime',
            'last_modified_at'  => 'datetime',
            'synced_at'        => 'datetime',
            'approved_at'      => 'datetime',
            'shipped_at'       => 'datetime',
            'completed_at'     => 'datetime',
            'order_total'      => 'decimal:2',
            'raw_payload'      => 'array',
        ];
    }

    /**
     * Expose the Acumatica order-level Description from the raw payload.
     * Payload shape: {"Description": {"value": "MTITO ANDEI KPC"}}
     */
    public function getDescriptionAttribute(): ?string
    {
        $payload = $this->raw_payload;
        if (! is_array($payload)) {
            return null;
        }

        $raw = $payload['Description'] ?? null;
        if (is_array($raw) && isset($raw['value'])) {
            $val = trim((string) $raw['value']);
            return $val !== '' ? $val : null;
        }
        if (is_string($raw)) {
            $val = trim($raw);
            return $val !== '' ? $val : null;
        }

        return null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AcumaticaSalesOrderLine::class, 'sales_order_id');
    }

    /** Resolved customer record from the customers table. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(AcumaticaCustomer::class, 'customer_acumatica_id', 'acumatica_id');
    }

    public function consultantUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }

    public function matchedEmails(): HasMany
    {
        return $this->hasMany(Email::class, 'matched_order_id');
    }

    /** Sales orders only — excludes quotes (QT) and credit notes (RC). */
    public function scopeSalesOrdersOnly(Builder $query): Builder
    {
        return $query->where('order_type', self::TYPE_SALES_ORDER);
    }

    public function scopeCreditNotesAndMore(Builder $query): Builder
    {
        return $query->whereIn('order_type', self::CREDIT_NOTES_AND_MORE_TYPES);
    }

    public function scopeOfOrderType(Builder $query, ?string $type): Builder
    {
        $type = strtoupper(trim((string) $type));

        if ($type === '' || $type === 'ALL') {
            return $query;
        }

        if ($type === 'CREDIT_NOTES_MORE') {
            return $query->creditNotesAndMore();
        }

        if ($type === 'OTHER') {
            return $query->whereNotIn('order_type', array_merge(
                [self::TYPE_SALES_ORDER],
                self::CREDIT_NOTES_AND_MORE_TYPES,
                ['QO'],
            ));
        }

        $normalized = self::normalizeOrderType($type) ?? $type;

        if ($normalized === self::TYPE_QUOTE) {
            return $query->whereIn('order_type', [self::TYPE_QUOTE, 'QO']);
        }

        return $query->where('order_type', $normalized);
    }

    public static function normalizeOrderType(?string $orderType): ?string
    {
        if ($orderType === null || $orderType === '') {
            return null;
        }

        $normalized = strtoupper(trim($orderType));

        return match ($normalized) {
            'QO' => self::TYPE_QUOTE,
            default => $normalized,
        };
    }

    public static function inferOrderType(?string $orderNbr, ?string $orderType = null): string
    {
        $type = self::normalizeOrderType($orderType) ?? '';
        $known = [
            self::TYPE_SALES_ORDER,
            self::TYPE_QUOTE,
            self::TYPE_CREDIT_NOTE,
            self::TYPE_CREDIT_MEMO,
            self::TYPE_PICK_LIST,
        ];

        if (in_array($type, $known, true)) {
            return $type;
        }

        $prefix = strtoupper(substr(preg_replace('/\s+/', '', (string) $orderNbr), 0, 2));

        return match ($prefix) {
            'QO' => self::TYPE_QUOTE,
            self::TYPE_QUOTE, self::TYPE_CREDIT_NOTE, self::TYPE_CREDIT_MEMO, self::TYPE_PICK_LIST => $prefix,
            default => self::TYPE_SALES_ORDER,
        };
    }
}
