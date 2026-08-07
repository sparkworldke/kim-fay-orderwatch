<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaCustomer extends Model { protected $table='sfa_customers'; protected $guarded=[]; protected function casts(): array { return ['is_active'=>'boolean','matched_at'=>'datetime']; } }
