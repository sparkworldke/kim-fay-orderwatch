<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DtcPrice extends Model
{
    protected $fillable = ['inventory_id','description','price_code','uom','price','currency_id','break_qty','effective_date','expiration_date','acumatica_record_id','synced_at'];
    protected function casts(): array { return ['price'=>'decimal:6','break_qty'=>'decimal:4','effective_date'=>'date','expiration_date'=>'date','synced_at'=>'datetime']; }
}
