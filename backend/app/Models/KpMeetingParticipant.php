<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class KpMeetingParticipant extends Model
{
    protected $fillable = ['meeting_id','user_id','role','status','invited_by','responded_at'];
    protected function casts(): array { return ['responded_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function meeting(): BelongsTo { return $this->belongsTo(KpMeeting::class, 'meeting_id'); }
}
