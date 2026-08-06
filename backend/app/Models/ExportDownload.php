<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExportDownload extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const TYPE_BACKORDERS = 'backorders';

    public const TYPE_FILL_RATE = 'fill_rate';

    public const TYPE_INVENTORY = 'inventory';
    public const TYPE_ITEMS_NOT_DELIVERED = 'items_not_delivered';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_BACKORDERS,
        self::TYPE_FILL_RATE,
        self::TYPE_INVENTORY,
        self::TYPE_ITEMS_NOT_DELIVERED,
    ];

    protected $fillable = [
        'user_id',
        'recipient_email',
        'delivery_mode',
        'public_token',
        'type',
        'status',
        'filename',
        'disk',
        'path',
        'size_bytes',
        'row_count',
        'filters',
        'error_message',
        'started_at',
        'finished_at',
        'expires_at',
        'downloaded_at',
        'emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->path
            && Storage::disk($this->disk)->exists($this->path)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function markExpiredIfNeeded(): void
    {
        if ($this->status === self::STATUS_READY
            && $this->expires_at !== null
            && $this->expires_at->isPast()) {
            $this->update(['status' => self::STATUS_EXPIRED]);
        }
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        $this->markExpiredIfNeeded();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => match ($this->type) {
                self::TYPE_BACKORDERS => 'Backorders',
                self::TYPE_FILL_RATE => 'Fill Rate',
                self::TYPE_INVENTORY => 'Inventory',
                self::TYPE_ITEMS_NOT_DELIVERED => 'Items Not Delivered',
                default => $this->type,
            },
            'status' => $this->status,
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'row_count' => $this->row_count,
            'filters' => $this->filters,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'downloaded_at' => $this->downloaded_at?->toIso8601String(),
            'recipient_email' => $this->recipient_email,
            'delivery_mode' => $this->delivery_mode,
            'emailed_at' => $this->emailed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'download_url' => $this->isReady() ? url("/api/downloads/{$this->id}/file") : null,
            'public_download_url' => $this->isReady() && $this->public_token
                ? url("/api/public/downloads/{$this->public_token}")
                : null,
            'ready' => $this->isReady(),
        ];
    }
}
