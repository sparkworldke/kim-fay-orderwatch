<?php
namespace App\Services\Operations;
use App\Models\AcumaticaSalesOrder;
use App\Models\FulfillmentHistorySnapshot;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use Illuminate\Support\Facades\DB;
class FulfillmentHistoryService {
 public function captureFirstCompleted(AcumaticaSalesOrder $order,array $raw,string $source='sales_order_sync',?int $runId=null): ?FulfillmentHistorySnapshot {
  if(strtolower(trim((string)$order->status))!=='completed')return null;
  $existing=FulfillmentHistorySnapshot::where('order_nbr',$order->acumatica_order_nbr)->first();if($existing)return $existing;
  $rawLines=$raw['DocumentDetails']??$raw['Details']??[];if(!is_array($rawLines)||$rawLines===[])return null;
  return DB::transaction(function()use($order,$raw,$rawLines,$source,$runId){
   $snapshot=FulfillmentHistorySnapshot::firstOrCreate(['order_nbr'=>$order->acumatica_order_nbr],['sales_order_id'=>$order->id,'customer_acumatica_id'=>$order->customer_acumatica_id,'order_date'=>$order->order_date?->toDateString(),'status'=>(string)$order->status,'observed_at'=>now(),'source'=>$source,'source_sync_run_id'=>$runId,'currency_id'=>$order->currency_id]);
   if(!$snapshot->wasRecentlyCreated)return $snapshot;
   $totals=['ordered'=>0.0,'delivered'=>0.0,'missing'=>0.0,'value'=>0.0];
   foreach($rawLines as $lineRaw){if(!is_array($lineRaw))continue;$m=SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);if(!$m['inventory_id'])continue;$explicit=array_key_exists('OpenQty',$lineRaw)||array_key_exists('OpenLineQty',$lineRaw);$delivered=(float)$m['qty_on_shipments'];$missing=$explicit?(float)$m['open_qty']:max((float)$m['order_qty']-$delivered-(float)$m['cancelled_qty'],0);if($missing<0)$missing=0;$value=SalesOrderLineFulfillmentDeriver::openLineValue($missing,(float)$m['unit_price']);$snapshot->lines()->create(['line_nbr'=>$m['line_nbr'],'inventory_id'=>$m['inventory_id'],'description'=>$m['description'],'order_qty'=>$m['order_qty'],'delivered_qty'=>$delivered,'cancelled_qty'=>$m['cancelled_qty'],'open_qty'=>$missing,'open_qty_explicit'=>$explicit,'unit_price'=>$m['unit_price'],'shortfall_amount'=>$value,'uom'=>$m['uom']]);$totals['ordered']+=$m['order_qty'];$totals['delivered']+=$delivered;$totals['missing']+=$missing;$totals['value']+=$value;}
   $snapshot->update(['total_ordered_qty'=>$totals['ordered'],'total_delivered_qty'=>$totals['delivered'],'total_missing_qty'=>$totals['missing'],'historical_shortfall_amount'=>round($totals['value'],2)]);return $snapshot->fresh('lines');
  });
 }
}
