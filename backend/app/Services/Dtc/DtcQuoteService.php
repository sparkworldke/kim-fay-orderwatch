<?php
namespace App\Services\Dtc;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\DtcPrice;
use App\Models\DtcQuote;
use App\Models\DtcQuoteConversion;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
class DtcQuoteService
{
    public const POS_ORDER_CUSTOMER_ID = 'CUST101470';

    public function __construct(private readonly AcumaticaClient $client) {}
    public function saveDraft(array $data,User $actor,?DtcQuote $quote=null): DtcQuote
    {
        $customer=AcumaticaCustomer::where('acumatica_id',$data['customer_acumatica_id'])->whereRaw('UPPER(customer_class)=?',['DTCACCOUNT'])->firstOrFail();
        return DB::transaction(function()use($data,$actor,$quote,$customer){
            $quote??=new DtcQuote(['public_ref'=>$this->nextRef(),'created_by'=>$actor->id,'status'=>'draft']);
            if($quote->exists && $quote->status!=='draft')throw new RuntimeException('Only draft quotes can be edited.');
            $lines=$this->pricedLines($data['lines']);$total=collect($lines)->sum('line_total');
            $quote->fill(['customer_acumatica_id'=>$customer->acumatica_id,'customer_name'=>$customer->name,'description'=>$data['description']??null,'quoted_total'=>$total,'currency_id'=>'KES'])->save();
            $quote->lines()->delete();$quote->lines()->createMany($lines);return $quote->load(['lines','creator:id,name','conversion']);
        });
    }
    public function submit(DtcQuote $quote): DtcQuote
    {
        if($quote->status==='submitted')return $quote->load(['lines','creator:id,name','conversion']);
        if($quote->status!=='draft')throw new RuntimeException('Quote cannot be submitted from its current status.');
        $quote->load('lines');
        $created=$this->client->createSalesOrder($quote->customer_acumatica_id,$quote->lines->map(fn($l)=>['inventory_id'=>$l->inventory_id,'qty'=>(float)$l->quantity,'unit_price'=>(float)$l->unit_price,'warehouse_id'=>$l->warehouse_id,'description'=>$l->description])->all(),$quote->public_ref,$quote->description,'QT',false);
        $quote->update(['acumatica_quote_nbr'=>$created['order_nbr'],'acumatica_quote_id'=>$created['order_id'],'status'=>'submitted','submitted_at'=>now(),'acumatica_response'=>$this->safeResponse($created['raw'])]);
        return $quote->fresh()->load(['lines','creator:id,name','conversion']);
    }
    public function convert(DtcQuote $quote,User $actor): DtcQuote
    {
        if(!$quote->acumatica_quote_nbr)throw new RuntimeException('Submit the quote to Acumatica before conversion.');
        $conversion=DtcQuoteConversion::firstOrCreate(['quote_id'=>$quote->id],['status'=>'pending']);
        if($conversion->status==='success'&&$conversion->acumatica_order_nbr)return $quote->fresh()->load(['lines','creator:id,name','conversion']);
        $claimed=DtcQuoteConversion::whereKey($conversion->id)->whereIn('status',['pending','failed'])->update(['status'=>'processing','attempt_count'=>DB::raw('attempt_count + 1'),'last_error'=>null,'converted_by'=>$actor->id]);
        if($claimed===0){$conversion->refresh();if($conversion->status==='success')return $quote->fresh()->load(['lines','creator:id,name','conversion']);throw new RuntimeException('This quote conversion is already in progress.');}
        $conversion->refresh();
        try {
            $remote=$this->client->fetchQuoteWithCustomerDetails($quote->acumatica_quote_nbr);if($remote===[])throw new RuntimeException('Acumatica QT could not be reloaded.');
            $remoteCustomer=(string)AcumaticaClient::scalarVal($remote['CustomerID']??$remote['Customer']??null);if($remoteCustomer!==''&&strcasecmp($remoteCustomer,$quote->customer_acumatica_id)!==0)throw new RuntimeException('Acumatica QT customer no longer matches the quote snapshot.');
            $quote->load('lines');$this->validateRemoteLines($quote,$remote);
            $customerDetails=$this->customerDetailsFromQuote($remote,$quote);
            $result=$this->client->createPosOrder(self::POS_ORDER_CUSTOMER_ID,$quote->lines->map(fn($l)=>['inventory_id'=>$l->inventory_id,'quantity'=>(float)$l->quantity])->all(),$customerDetails);
            $posLines=$this->posLines($result['raw']);
            DB::transaction(function()use($conversion,$quote,$actor,$result,$customerDetails,$posLines){$conversion->update(['status'=>'success','acumatica_order_nbr'=>$result['order_nbr'],'acumatica_order_id'=>$result['order_id'],'customer_details_snapshot'=>$customerDetails,'pos_lines_snapshot'=>$posLines,'order_total'=>$result['order_total'],'price_variance'=>round($result['order_total']-(float)$quote->quoted_total,2),'last_error'=>null,'converted_by'=>$actor->id,'converted_at'=>now(),'response_snapshot'=>$this->safeResponse($result['raw'])]);$quote->update(['status'=>'converted','customer_name'=>$customerDetails['name']?:$quote->customer_name,'customer_details_snapshot'=>$customerDetails]);$this->persistSalesOrder($quote,$result,$posLines,$customerDetails);});
        }catch(Throwable $e){$conversion->update(['status'=>'failed','last_error'=>$e->getMessage(),'converted_by'=>$actor->id]);throw $e;}
        return $quote->fresh()->load(['lines','creator:id,name','conversion']);
    }
    private function pricedLines(array $input): array
    {
        $today=now('Africa/Nairobi')->toDateString();$out=[];
        foreach($input as $row){$id=trim((string)$row['inventory_id']);$qty=(float)$row['quantity'];if($id===''||$qty<=0)continue;
            $item=AcumaticaInventoryItem::where('inventory_id',$id)->where('qty_available','>',0)->where(function($q){$q->whereNull('item_status')->orWhereNotIn('item_status',['Inactive','Deleted','No Sales']);})->first();if(!$item)throw new RuntimeException("{$id} is inactive or unavailable.");
            // Prefer inventory-linked dtc_price_list (plugin design); fall back to legacy dtc_prices.
            $listPrice=\App\Models\DtcPriceList::where('inventory_id',$id)->where('price_code','DTCACCOUNT')->first();
            $unit=null;$uom='PIECE';$desc=$item->description;$effectiveDate=null;
            if($listPrice&&$listPrice->effectivePrice()>0){
                $unit=$listPrice->effectivePrice();$uom=(string)($listPrice->uom?:'PIECE');$desc=$item->description??$listPrice->description;$effectiveDate=$listPrice->price_synced_at?->toDateString();
            }else{
                $price=DtcPrice::where('inventory_id',$id)->where('price_code','DTCACCOUNT')->where(fn($q)=>$q->whereNull('effective_date')->orWhere('effective_date','<=',$today))->where(fn($q)=>$q->whereNull('expiration_date')->orWhere('expiration_date','>=',$today))->where('break_qty','<=',$qty)->orderByDesc('break_qty')->orderByDesc('effective_date')->first();
                if(!$price)throw new RuntimeException("{$id} has no effective DTCACCOUNT price.");
                $unit=(float)$price->price;$uom=$price->uom;$desc=$item->description??$price->description;$effectiveDate=$price->effective_date?->toDateString();
            }
            $total=round($qty*(float)$unit,2);$out[]=['inventory_id'=>$id,'description'=>$desc,'uom'=>$uom,'warehouse_id'=>$item->default_warehouse_id,'quantity'=>$qty,'unit_price'=>$unit,'line_total'=>$total,'price_effective_date'=>$effectiveDate];
        }if($out===[])throw new RuntimeException('Add at least one valid quote line.');return $out;
    }
    private function validateRemoteLines(DtcQuote $quote,array $remote): void
    {
        $details=is_array($remote['Details']??null)?$remote['Details']:[];$remoteMap=[];
        foreach($details as $line){$id=(string)AcumaticaClient::scalarVal($line['InventoryID']??$line['InventoryId']??null);if($id!=='')$remoteMap[$id]=(float)(AcumaticaClient::val($line['OrderQty']??$line['Quantity']??null)??0);}
        foreach($quote->lines as $line){
            if($remoteMap!==[]&&(!isset($remoteMap[$line->inventory_id])||abs($remoteMap[$line->inventory_id]-(float)$line->quantity)>0.0001))throw new RuntimeException("Acumatica QT line {$line->inventory_id} no longer matches the quote snapshot.");
        }
    }
    private function customerDetailsFromQuote(array $remote,DtcQuote $quote): array
    {
        $expanded=is_array($remote['CustomerDetails'][0]??null)?$remote['CustomerDetails'][0]:[];
        $customer=is_array($remote['ResolvedCustomer']??null)?$remote['ResolvedCustomer']:[];
        $local=AcumaticaCustomer::where('acumatica_id',$quote->customer_acumatica_id)->first();
        $billing=is_array($local?->billing_address)?$local->billing_address:[];
        $value=fn(array $keys)=>collect($keys)->map(fn($key)=>AcumaticaClient::scalarVal($expanded[$key]??$customer[$key]??null))->first(fn($v)=>$v!==null);
        return array_filter(['name'=>$value(['AccountName','CustomerName'])??$quote->customer_name,'email'=>$value(['AccountEmail','Email'])??$local?->email,'phone'=>$value(['Phone','Phone1'])??$local?->phone,'address_line1'=>$value(['AddressLine1'])??($billing['address_line1']??$billing['line1']??null),'address_line2'=>$value(['AddressLine2'])??($billing['address_line2']??$billing['line2']??null)],fn($v)=>$v!==null&&$v!=='');
    }
    public static function extractCustomerDetails(array $raw,?string $fallbackName=null): array
    {
        $detail=is_array($raw['CustomerDetails'][0]??null)?$raw['CustomerDetails'][0]:[];
        $get=fn(string $key)=>AcumaticaClient::scalarVal($detail[$key]??null);
        return array_filter(['name'=>$get('AccountName')??$fallbackName,'email'=>$get('AccountEmail'),'phone'=>$get('Phone'),'address_line1'=>$get('AddressLine1'),'address_line2'=>$get('AddressLine2')],fn($v)=>$v!==null&&$v!=='');
    }
    private function posLines(array $raw): array
    {
        $rows=is_array($raw['DocumentDetails']??null)?$raw['DocumentDetails']:[];
        return collect($rows)->map(function($line){$qty=(float)(AcumaticaClient::val($line['Quantity']??$line['OrderQty']??null)??0);$unit=(float)(AcumaticaClient::val($line['UnitPrice']??$line['Price']??null)??0);$total=(float)(AcumaticaClient::val($line['ExtendedPrice']??$line['ExtPrice']??null)??($qty*$unit));return ['id'=>$line['id']??null,'inventory_id'=>AcumaticaClient::scalarVal($line['InventoryID']??null),'description'=>AcumaticaClient::scalarVal($line['Description']??$line['LineDescription']??null),'quantity'=>$qty,'uom'=>AcumaticaClient::scalarVal($line['UOM']??null),'unit_price'=>$unit,'line_total'=>$total];})->filter(fn($line)=>$line['inventory_id'])->values()->all();
    }
    public function updateConvertedCustomer(DtcQuote $quote,array $details): DtcQuote
    {
        $conversion=$quote->conversion;if(!$conversion||$conversion->status!=='success'||!$conversion->acumatica_order_id)throw new RuntimeException('Only a successfully converted POS order can be updated.');
        $response=$this->client->updatePosOrderCustomerDetails($conversion->acumatica_order_id,$details);
        $stored=array_filter($details,fn($v)=>$v!==null&&$v!=='');$posLines=$this->posLines($response)?:($conversion->pos_lines_snapshot??[]);
        DB::transaction(function()use($quote,$conversion,$stored,$posLines,$response){$quote->update(['customer_name'=>$stored['name']??$quote->customer_name,'customer_details_snapshot'=>$stored]);$conversion->update(['customer_details_snapshot'=>$stored,'pos_lines_snapshot'=>$posLines,'order_total'=>(float)(AcumaticaClient::val($response['OrderTotal']??null)??$conversion->order_total),'response_snapshot'=>$this->safeResponse($response)]);});
        return $quote->fresh()->load(['lines','creator:id,name','conversion']);
    }
    private function persistSalesOrder(DtcQuote $quote,array $result,array $posLines,array $customerDetails): void
    {
        $orderCustomerName=AcumaticaCustomer::where('acumatica_id',self::POS_ORDER_CUSTOMER_ID)->value('name')??'Calltronix DTC/DTB';
        $order=AcumaticaSalesOrder::updateOrCreate(['acumatica_order_nbr'=>$result['order_nbr']],['order_type'=>'SO','customer_acumatica_id'=>self::POS_ORDER_CUSTOMER_ID,'customer_name'=>$customerDetails['name']??$orderCustomerName,'customer_order'=>$quote->public_ref,'status'=>(string)(AcumaticaClient::scalarVal($result['raw']['Status']??null)?:'Open'),'order_date'=>now('Africa/Nairobi')->toDateString(),'order_total'=>$result['order_total'],'currency_id'=>$quote->currency_id,'import_source'=>'dtc_quote_conversion','raw_payload'=>[...$this->safeResponse($result['raw']),'quoted_customer_id'=>$quote->customer_acumatica_id,'quoted_customer_name'=>$quote->customer_name,'customer_details'=>$customerDetails],'synced_at'=>now()]);
        $order->lines()->delete();$rows=$posLines?:$quote->lines->map(fn($l)=>['inventory_id'=>$l->inventory_id,'description'=>$l->description,'quantity'=>$l->quantity,'unit_price'=>$l->unit_price,'line_total'=>$l->line_total,'uom'=>$l->uom])->all();foreach($rows as $i=>$line)$order->lines()->create(['line_nbr'=>$i+1,'inventory_id'=>$line['inventory_id'],'description'=>$line['description']??null,'order_qty'=>$line['quantity'],'unit_price'=>$line['unit_price'],'ext_cost'=>$line['line_total'],'warehouse_id'=>$line['warehouse_id']??null,'uom'=>$line['uom']??null]);
    }
    private function safeResponse(array $raw): array
    {
        return array_filter(['id'=>$raw['id']??null,'OrderNbr'=>$raw['OrderNbr']??null,'OrderType'=>$raw['OrderType']??null,'Status'=>$raw['Status']??null,'OrderTotal'=>$raw['OrderTotal']??null,'CurrencyID'=>$raw['CurrencyID']??null,'pos_order_customer_id'=>self::POS_ORDER_CUSTOMER_ID],fn($value)=>$value!==null);
    }
    private function nextRef(): string { $prefix='DTC-'.now()->format('Ym').'-';$last=DtcQuote::where('public_ref','like',$prefix.'%')->lockForUpdate()->max('public_ref');return $prefix.str_pad((string)(((int)substr((string)$last,-5))+1),5,'0',STR_PAD_LEFT); }
}
