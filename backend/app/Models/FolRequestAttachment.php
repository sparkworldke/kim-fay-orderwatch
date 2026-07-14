<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolRequestAttachment extends Model
{
    protected $fillable = [
        'fol_request_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(FolRequest::class, 'fol_request_id');
    }
}
