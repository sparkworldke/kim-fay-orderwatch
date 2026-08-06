<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStockSummary extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary_date' => 'date',
            'qty_on_hand' => 'decimal:4',
            'qty_available' => 'decimal:4',
            'qty_allocated' => 'decimal:4',
            'msi' => 'decimal:4',
            'months_of_cover' => 'decimal:4',
            'source_refreshed_at' => 'datetime',
        ];
    }
}
