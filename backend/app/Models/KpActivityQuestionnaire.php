<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpActivityQuestionnaire extends Model
{
    protected $fillable = ['purpose_id', 'activity_type', 'version', 'questions', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'is_active' => 'boolean'];
    }

    public function purpose(): BelongsTo { return $this->belongsTo(KpMeetingPurpose::class, 'purpose_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
