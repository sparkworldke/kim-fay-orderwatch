<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class DtcQuote extends Model
{
    protected $fillable=['public_ref','acumatica_quote_nbr','acumatica_quote_id','customer_acumatica_id','customer_name','customer_details_snapshot','ship_to_phone','ship_to_email','ship_to_address_line1','ship_to_address_line2','status','description','quoted_total','currency_id','created_by','submitted_at','acumatica_response'];
    protected function casts(): array { return ['quoted_total'=>'decimal:2','submitted_at'=>'datetime','acumatica_response'=>'array','customer_details_snapshot'=>'array']; }
    public function lines(): HasMany { return $this->hasMany(DtcQuoteLine::class,'quote_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function conversion(): HasOne { return $this->hasOne(DtcQuoteConversion::class,'quote_id'); }
}
