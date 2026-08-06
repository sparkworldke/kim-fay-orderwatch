<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DtcQuoteConversion extends Model
{
    protected $fillable=['quote_id','status','acumatica_order_nbr','acumatica_order_id','customer_details_snapshot','pos_lines_snapshot','order_total','price_variance','attempt_count','last_error','converted_by','converted_at','response_snapshot'];
    protected function casts(): array { return ['order_total'=>'decimal:2','price_variance'=>'decimal:2','converted_at'=>'datetime','response_snapshot'=>'array','customer_details_snapshot'=>'array','pos_lines_snapshot'=>'array']; }
    public function quote(): BelongsTo { return $this->belongsTo(DtcQuote::class,'quote_id'); }
}
