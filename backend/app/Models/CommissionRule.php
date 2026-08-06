<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean',
            'eligible_user_ids' => 'array', 'eligible_team_ids' => 'array',
            'eligible_inventory_ids' => 'array', 'eligible_brands' => 'array',
            'eligible_customer_classes' => 'array', 'excluded_order_types' => 'array',
            'cap_amount' => 'decimal:2', 'fixed_bonus' => 'decimal:2',
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(CommissionRuleTier::class)->orderBy('attainment_from_pct');
    }
}
