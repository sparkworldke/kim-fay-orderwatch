<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlySkuSummary extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'ordered_qty' => 'decimal:4',
            'delivered_qty' => 'decimal:4',
            'missed_qty' => 'decimal:4',
            'missed_revenue' => 'decimal:4',
            'priced_missed_qty' => 'decimal:4',
            'revenue_complete' => 'boolean',
            'source_refreshed_at' => 'datetime',
        ];
    }
}
