<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaStartEndDay extends Model { protected $table='sfa_start_end_days'; protected $guarded=[]; protected function casts(): array { return ['start_day_time'=>'datetime','close_day_time'=>'datetime']; } }
