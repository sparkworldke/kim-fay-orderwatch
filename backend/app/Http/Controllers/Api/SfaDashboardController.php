<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\SfaDailyPerformance;
use App\Models\SfaRep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class SfaDashboardController extends Controller {
    public function index(Request $request): JsonResponse { $date=$request->date('date')?->toDateString() ?? now(config('sfa_sync.timezone'))->toDateString(); $reps=SfaRep::where('team','GT')->count(); $rows=SfaDailyPerformance::query()->whereDate('date',$date)->whereIn('rep_id',SfaRep::where('team','GT')->select('id'))->get(); return response()->json(['team'=>'GT','date'=>$date,'summary'=>['reps'=>$reps,'visits'=>$rows->sum('actual_visits'),'successful_visits'=>$rows->sum('successful_visits'),'sales'=>(float)$rows->sum('sales_achieved')],'performances'=>$rows]); }
}
