<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpMeeting extends Model
{
    protected $fillable = [
        'title',
        'purpose_id', 'meeting_mode', 'is_internal', 'is_planned',
        'notes',
        'previous_notes', 'current_notes', 'outcome', 'follow_up_date', 'no_follow_up_reason', 'b2b_details',
        'customer_acumatica_id',
        'customer_name',
        'starts_at',
        'ends_at',
        'location',
        'status',
        'completed_at', 'cancelled_at',
        'outlook_event_id',
        'created_by',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'follow_up_date' => 'date', 'b2b_details' => 'array', 'is_internal' => 'boolean',
            'is_planned' => 'boolean', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purpose(): BelongsTo { return $this->belongsTo(KpMeetingPurpose::class, 'purpose_id'); }
    public function actions(): HasMany { return $this->hasMany(KpMeetingAction::class, 'meeting_id'); }
    public function participants(): HasMany { return $this->hasMany(KpMeetingParticipant::class, 'meeting_id'); }
}
