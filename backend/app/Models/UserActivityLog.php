<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_PAGE_VIEW = 'page_view';

    public const TYPE_DOWNLOAD = 'download';

    public const TYPE_ACTION = 'action';

    protected $fillable = [
        'user_id',
        'activity_type',
        'path',
        'page_title',
        'method',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
