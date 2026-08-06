<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class KpMeetingTarget extends Model
{
    protected $fillable = ['user_id','month','target','set_by'];
    protected function casts(): array { return ['month'=>'date']; }
}
