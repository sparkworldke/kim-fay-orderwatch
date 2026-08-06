<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImportLog extends Model
{
    protected $fillable = [
        'status',
        'file_name',
        'file_path',
        'total_rows',
        'processed_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'unmatched_count',
        'error_count',
        'errors',
        'triggered_by_user_id',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
