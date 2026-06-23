<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReportDeliveryLog extends Model
{
    protected $fillable = [
        'daily_report_run_id',
        'recipient_email',
        'recipient_role',
        'delivery_status',
        'provider_message_id',
        'error_message',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(DailyReportRun::class, 'daily_report_run_id');
    }
}