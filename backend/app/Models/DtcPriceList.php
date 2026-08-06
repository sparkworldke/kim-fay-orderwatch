<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DTCACCOUNT price list row, 1:1 with inventory_id.
 * product sync fills catalog fields; price sync fills dtc_price from SalesPricesInquiry.
 */
class DtcPriceList extends Model
{
    protected $table = 'dtc_price_list';

    protected $fillable = [
        'inventory_id',
        'description',
        'brand',
        'product_type',
        'uom',
        'dtc_price',
        'break_qty',
        'default_price',
        'currency_id',
        'price_code',
        'price_type',
        'taxation',
        'tax',
        'default_warehouse_id',
        'item_status',
        'qty_available',
        'qty_on_hand',
        'in_catalog',
        'effective_date',
        'expiration_date',
        'product_synced_at',
        'price_synced_at',
        'synced_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'dtc_price' => 'decimal:6',
            'break_qty' => 'decimal:4',
            'default_price' => 'decimal:6',
            'qty_available' => 'decimal:4',
            'qty_on_hand' => 'decimal:4',
            'in_catalog' => 'boolean',
            'effective_date' => 'date',
            'expiration_date' => 'date',
            'product_synced_at' => 'datetime',
            'price_synced_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** Kenya standard VAT applied to TAXABLE DTC prices. */
    public const VAT_RATE = 0.16;

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(AcumaticaInventoryItem::class, 'inventory_id', 'inventory_id');
    }

    /** Net price before VAT (DTCACCOUNT / Excel price, else stock default). */
    public function netPrice(): float
    {
        $dtc = (float) ($this->dtc_price ?? 0);
        if ($dtc > 0) {
            return $dtc;
        }

        return (float) ($this->default_price ?? 0);
    }

    /**
     * True when Tax Category / taxation is TAXABLE (or equivalent).
     * Non-taxable / empty → no VAT.
     */
    public function isTaxable(): bool
    {
        $raw = strtoupper(trim((string) ($this->taxation ?? '')));
        if ($raw === '') {
            $raw = strtoupper(trim((string) ($this->tax ?? '')));
        }
        if ($raw === '') {
            return false;
        }

        if (in_array($raw, ['TAXABLE', 'VAT', 'STANDARD', 'STANDARD VAT', 'VATABLE'], true)) {
            return true;
        }

        // Explicit non-taxable labels
        if (in_array($raw, ['EXEMPT', 'ZERO', 'ZERO RATED', 'ZERORATED', 'NONTAXABLE', 'NON-TAXABLE', 'OUT OF SCOPE'], true)) {
            return false;
        }

        return str_contains($raw, 'TAXABLE') || $raw === 'TAX';
    }

    /** VAT amount on the net price (0 when not taxable). */
    public function vatAmount(): float
    {
        $net = $this->netPrice();
        if ($net <= 0 || ! $this->isTaxable()) {
            return 0.0;
        }

        return round($net * self::VAT_RATE, 6);
    }

    /**
     * Effective sell price for quotes / list:
     * TAXABLE → net + 16% VAT; otherwise net.
     */
    public function effectivePrice(): float
    {
        $net = $this->netPrice();
        if ($net <= 0) {
            return 0.0;
        }
        if ($this->isTaxable()) {
            return round($net * (1 + self::VAT_RATE), 6);
        }

        return $net;
    }

    public function vatRate(): float
    {
        return $this->isTaxable() ? self::VAT_RATE : 0.0;
    }
}
