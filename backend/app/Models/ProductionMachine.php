<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductionMachine extends Model
{
    protected $fillable = ['name'];

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(ProductionSkuPlan::class, 'production_machine_plan');
    }
}
