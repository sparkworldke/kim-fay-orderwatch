<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaConfig extends Model
{
    protected $fillable = [
        'base_url',
        'endpoint',
        'version',
        'tenant',
        'grant_type',
        'scope',
        'username',
        'password_encrypted',
        'client_id_encrypted',
        'client_secret_encrypted',
        'token_url',
        'endpoint_version',
        'last_validated_at',
        'health_status',
    ];

    protected function casts(): array
    {
        return [
            'last_validated_at' => 'datetime',
        ];
    }
}
