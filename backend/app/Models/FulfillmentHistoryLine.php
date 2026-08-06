<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FulfillmentHistoryLine extends Model {
 protected $fillable=['snapshot_id','line_nbr','inventory_id','description','order_qty','delivered_qty','cancelled_qty','open_qty','open_qty_explicit','unit_price','shortfall_amount','uom'];
 protected function casts(): array{return ['open_qty_explicit'=>'boolean'];}
}
