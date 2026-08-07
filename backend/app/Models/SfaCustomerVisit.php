<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaCustomerVisit extends Model { protected $table='sfa_customer_visits'; protected $guarded=[]; protected function casts(): array { return ['checkin_time'=>'datetime','checkout_time'=>'datetime']; } }
