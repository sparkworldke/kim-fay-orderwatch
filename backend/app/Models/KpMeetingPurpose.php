<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class KpMeetingPurpose extends Model
{
    protected $fillable = ['name', 'activity_types', 'allows_internal', 'customer_required', 'is_active', 'sort_order'];
    protected function casts(): array { return ['activity_types' => 'array', 'allows_internal' => 'boolean', 'customer_required' => 'boolean', 'is_active' => 'boolean']; }
}
