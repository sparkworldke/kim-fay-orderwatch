<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaRep extends Model { protected $table='sfa_reps'; public $incrementing=false; protected $guarded=[]; protected function casts(): array { return ['matched_at'=>'datetime']; } }
