<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class KpMeetingPurpose extends Model
{
    protected $fillable = ['name', 'allows_internal', 'is_active', 'sort_order'];
    protected function casts(): array { return ['allows_internal' => 'boolean', 'is_active' => 'boolean']; }
}
