<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\User;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class KpItemsNotOrderedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureCanView($request->user());
        $bucket = $request->integer('bucket', 30);
        abort_unless(in_array($bucket, [30,60,90], true), 422, 'Bucket must be 30, 60, or 90 days.');
        $q = trim((string) $request->input('q', ''));

        $customerQuery = DataScope::applyCustomerScope(
            AcumaticaCustomer::query()->where('customer_class', 'like', 'KP%'),
            $request->user(), 'acumatica_customers.acumatica_id'
        );
        if ($q !== '') $customerQuery->where(fn($x)=>$x->where('name','like',"%{$q}%")->orWhere('acumatica_id','like',"%{$q}%"));
        $customers = $customerQuery->get(['acumatica_id','name','customer_class'])->keyBy('acumatica_id');
        if ($customers->isEmpty()) return response()->json(['data'=>[],'summary'=>$this->summary([]),'data_as_of'=>now('Africa/Nairobi')->toIso8601String()]);

        $rows = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o','o.id','=','l.sales_order_id')
            ->whereIn('o.customer_acumatica_id',$customers->keys())
            ->where('o.order_type','SO')->whereNotNull('o.order_date')->whereNotNull('l.inventory_id')
            ->where(function($x){ $x->whereNull('o.import_source')->orWhere('o.import_source','not like','%fol%'); })
            ->select(['o.customer_acumatica_id','l.inventory_id','l.description','l.uom','l.order_qty','l.unit_price','o.order_date'])
            ->orderBy('o.order_date')->get();

        $today = now('Africa/Nairobi')->startOfDay(); $items=[];
        foreach($rows->groupBy(fn($r)=>$r->customer_acumatica_id.'|'.$r->inventory_id) as $group){
            $dates=$group->pluck('order_date')->map(fn($d)=>Carbon::parse($d,'Africa/Nairobi')->startOfDay())->unique(fn($d)=>$d->toDateString())->sort()->values();
            if($dates->count()<2) continue;
            $interval=max(1,(int)round($dates->first()->diffInDays($dates->last())/($dates->count()-1)));
            $expected=$dates->last()->copy()->addDays($interval); if($expected->gt($today)) continue;
            $overdue=$expected->diffInDays($today); if($overdue<$bucket) continue;
            $first=$group->last(); $avgQty=(float)$group->avg('order_qty'); $avgPrice=(float)$group->avg('unit_price');
            $items[]=['customer_id'=>$first->customer_acumatica_id,'customer_name'=>$customers[$first->customer_acumatica_id]->name,
                'inventory_id'=>$first->inventory_id,'description'=>$first->description,'uom'=>$first->uom,
                'order_count'=>$dates->count(),'avg_interval_days'=>$interval,'last_order_date'=>$dates->last()->toDateString(),
                'next_expected_date'=>$expected->toDateString(),'days_overdue'=>$overdue,'bucket'=>$overdue>=90?90:($overdue>=60?60:30),
                'risk'=>$overdue>=90?'lost_sales':($overdue>=60?'medium':'low'),'expected_qty'=>round($avgQty,2),
                'avg_unit_price'=>round($avgPrice,2),'opportunity_value'=>round($avgQty*$avgPrice,2)];
        }
        usort($items, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

        // Group by customer for accordion UI: "Titus Enterprise · 10 items"
        $byCustomer = [];
        foreach ($items as $item) {
            $cid = (string) $item['customer_id'];
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $item['customer_name'] ?: $cid,
                    'item_count' => 0,
                    'opportunity_value' => 0.0,
                    'label' => '',
                    'items' => [],
                ];
            }
            $byCustomer[$cid]['items'][] = $item;
            $byCustomer[$cid]['item_count']++;
            $byCustomer[$cid]['opportunity_value'] += (float) ($item['opportunity_value'] ?? 0);
        }
        foreach ($byCustomer as &$group) {
            $n = $group['item_count'];
            $group['opportunity_value'] = round($group['opportunity_value'], 2);
            $group['label'] = $group['customer_name'].' · '.$n.' '.($n === 1 ? 'item' : 'items');
            usort($group['items'], fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);
        }
        unset($group);
        $grouped = array_values($byCustomer);
        usort($grouped, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        return response()->json([
            'data' => $items,
            'grouped' => $grouped,
            'summary' => $this->summary($items),
            'calculation_basis' => 'Average historical unit price × average ordered quantity; minimum 2 distinct SO dates; FOL excluded. Grouped by customer for the accordion list.',
            'data_as_of' => now('Africa/Nairobi')->toIso8601String(),
        ]);
    }

    private function summary(array $items): array
    {
        return [
            'item_count' => count($items),
            'customer_count' => count(array_unique(array_column($items, 'customer_id'))),
            'opportunity_value' => round(array_sum(array_column($items, 'opportunity_value')), 2),
            'by_bucket' => collect($items)->groupBy('bucket')->map(fn ($x) => [
                'count' => $x->count(),
                'value' => round($x->sum('opportunity_value'), 2),
            ])->all(),
        ];
    }
    private function ensureCanView(?User $user): void
    { abort_if(!$user,401); abort_unless($user->role==='Administrator'||$user->hasPermission('kp.crm.items_not_ordered.view'),403); }
}
