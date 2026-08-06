<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class KpMeetingAction extends Model
{
    protected $fillable = ['meeting_id','category_id','category_snapshot','description','owner_user_id','due_date','status','completed_at'];
    protected function casts(): array { return ['due_date'=>'date','completed_at'=>'datetime']; }
    public function category(): BelongsTo { return $this->belongsTo(KpMeetingActionCategory::class, 'category_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function meeting(): BelongsTo { return $this->belongsTo(KpMeeting::class, 'meeting_id'); }
}
