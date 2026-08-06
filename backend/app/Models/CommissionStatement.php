<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CommissionStatement extends Model
{
    protected $guarded = [];
    public function period(): BelongsTo { return $this->belongsTo(CommissionPeriod::class, 'commission_period_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function entries(): HasMany { return $this->hasMany(CommissionEntry::class); }
    public function adjustments(): HasMany { return $this->hasMany(CommissionAdjustment::class); }
}
