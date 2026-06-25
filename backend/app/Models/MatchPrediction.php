<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPrediction extends Model
{
    protected $fillable = [
        'email_id',
        'order_id',
        'order_nbr',
        'confidence',
        'match_type',
        'reasoning',
        'is_top_prediction',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'confidence'        => 'decimal:4',
            'is_top_prediction' => 'boolean',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'order_id');
    }
}