<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DtcQuoteLine extends Model
{
    protected $fillable=['quote_id','inventory_id','description','uom','warehouse_id','quantity','unit_price','line_total','price_effective_date'];
    protected function casts(): array { return ['quantity'=>'decimal:4','unit_price'=>'decimal:6','line_total'=>'decimal:2','price_effective_date'=>'date']; }
}
