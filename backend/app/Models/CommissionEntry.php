<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CommissionEntry extends Model { protected $guarded = []; protected function casts(): array { return ['order_date'=>'date','is_excluded'=>'boolean','order_snapshot'=>'array']; } }
