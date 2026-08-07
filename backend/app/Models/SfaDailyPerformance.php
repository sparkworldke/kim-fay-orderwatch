<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaDailyPerformance extends Model { protected $table='sfa_daily_performances'; protected $guarded=[]; protected function casts(): array { return ['date'=>'date','first_checkin'=>'datetime','last_checkout'=>'datetime']; } }
