<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaSyncLog extends Model { protected $table='sfa_sync_logs'; protected $guarded=[]; protected function casts(): array { return ['data_date'=>'date','started_at'=>'datetime','completed_at'=>'datetime']; } }
