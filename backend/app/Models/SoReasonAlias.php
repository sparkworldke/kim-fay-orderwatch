<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoReasonAlias extends Model
{
    public $timestamps = false;

    protected $fillable = ['alias', 'sub_reason_code'];
}