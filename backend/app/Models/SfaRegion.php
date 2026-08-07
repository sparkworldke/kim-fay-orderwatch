<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaRegion extends Model { protected $table='sfa_regions'; public $incrementing=false; protected $guarded=[]; protected function casts(): array { return ['is_active'=>'boolean']; } }
