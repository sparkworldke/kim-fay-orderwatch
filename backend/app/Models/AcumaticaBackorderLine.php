<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaBackorderLine extends Model
{
    public const REASON_CODES = [
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

    protected $fillable = [
        'order_nbr',
        'inventory_id',
        'customer_acumatica_id',
        'customer_name',
        'order_qty',
        'shipped_qty',
        'qty_on_shipments',
        'open_qty',
        'cancelled_qty',
        'backorder_qty',
        'fulfillment_status',
        'qty_at_approval',
        'reason_code',
        'reason_notes',
        'reason_updated_by',
        'reason_updated_at',
        'unit_price',
        'revenue_at_risk',
        'warehouse_id',
        'uom',
        'currency_id',
        'scheduled_shipment_date',
        'requested_on',
        'sync_run_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'order_qty'               => 'decimal:4',
            'shipped_qty'             => 'decimal:4',
            'qty_on_shipments'        => 'decimal:4',
            'open_qty'                => 'decimal:4',
            'cancelled_qty'           => 'decimal:4',
            'backorder_qty'           => 'decimal:4',
            'qty_at_approval'         => 'decimal:4',
            'unit_price'              => 'decimal:4',
            'revenue_at_risk'         => 'decimal:2',
            'reason_updated_at'       => 'datetime',
            'scheduled_shipment_date' => 'date',
            'requested_on'            => 'date',
            'synced_at'               => 'datetime',
        ];
    }
}
