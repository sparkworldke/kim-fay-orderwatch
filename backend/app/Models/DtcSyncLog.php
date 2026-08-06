<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DtcSyncLog extends Model
{
    protected $fillable=['sync_type','status','storage_path','original_filename','queue_job_id','records_processed','progress','error_message','triggered_by','started_at','finished_at','metadata'];
    protected function casts(): array { return ['started_at'=>'datetime','finished_at'=>'datetime','metadata'=>'array','progress'=>'array']; }
    public function triggeredBy(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by'); }
}
