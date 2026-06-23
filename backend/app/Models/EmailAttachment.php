<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    protected $fillable = [
        'email_id', 'graph_attachment_id', 'name', 'content_type', 'size', 'is_inline',
        'extracted_text', 'extraction_status', 'extraction_confidence', 'extraction_method', 'extraction_error',
    ];

    protected function casts(): array
    {
        return ['is_inline' => 'boolean'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
