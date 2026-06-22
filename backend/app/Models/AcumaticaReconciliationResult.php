<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaReconciliationResult extends Model
{
    protected $fillable = [
        'sync_run_id',
        'resource_type',
        'resource_id',
        'field_name',
        'local_value',
        'acumatica_value',
        'severity',
        'remediation_status',
    ];
}
