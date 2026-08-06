<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FulfillmentHistorySnapshot extends Model {
 protected $fillable=['sales_order_id','order_nbr','customer_acumatica_id','order_date','status','observed_at','source','source_sync_run_id','total_ordered_qty','total_delivered_qty','total_missing_qty','historical_shortfall_amount','currency_id','metadata'];
 protected function casts(): array{return ['order_date'=>'date','observed_at'=>'datetime','metadata'=>'array'];}
 public function lines():HasMany{return $this->hasMany(FulfillmentHistoryLine::class,'snapshot_id');}
}
