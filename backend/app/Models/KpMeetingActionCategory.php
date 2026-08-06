<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class KpMeetingActionCategory extends Model
{
    protected $fillable = ['name', 'is_main', 'is_active', 'sort_order'];
    protected function casts(): array { return ['is_main' => 'boolean', 'is_active' => 'boolean']; }
}
