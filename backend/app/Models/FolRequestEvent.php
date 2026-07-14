<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolRequestEvent extends Model
{
    protected $fillable = [
        'fol_request_id',
        'event_type',
        'actor_user_id',
        'comment',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FolRequest::class, 'fol_request_id');
    }
}
