<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SfaSyncState extends Model { protected $table='sfa_sync_states'; protected $guarded=[]; protected function casts(): array { return ['is_enabled'=>'boolean','last_sync_at'=>'datetime','last_success_at'=>'datetime','last_data_date'=>'date']; } }
