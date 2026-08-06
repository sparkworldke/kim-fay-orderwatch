<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CommissionPeriod extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['period_month'=>'date','shadow_mode'=>'boolean','calculated_at'=>'datetime','approved_at'=>'datetime','locked_at'=>'datetime']; }
    public function statements(): HasMany { return $this->hasMany(CommissionStatement::class); }
    public function events(): HasMany { return $this->hasMany(CommissionEvent::class); }
}
