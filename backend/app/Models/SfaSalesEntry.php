<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaSalesEntry extends Model { protected $table='sfa_sales_entries'; protected $guarded=[]; protected function casts(): array { return ['entry_time'=>'datetime','quantity'=>'decimal:4','value_sold'=>'decimal:2']; } }
