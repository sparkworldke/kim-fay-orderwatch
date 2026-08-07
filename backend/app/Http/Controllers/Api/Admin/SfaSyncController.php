<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Services\Sfa\SfaSyncService;
use App\Services\Sfa\SfaCustomerMatcher;
use App\Models\SfaCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class SfaSyncController extends Controller {
    public function status(SfaSyncService $sync): JsonResponse { return response()->json($sync->status()); }
    public function run(Request $request,SfaSyncService $sync): JsonResponse { $data=$request->validate(['table'=>['required','string','in:reps,customers']]); $log=$sync->run($data['table']); return response()->json($log,$log->status==='success'?200:502); }
    public function matches(Request $request): JsonResponse {
        $status=$request->string('status')->toString();
        $query=SfaCustomer::query()->leftJoin('acumatica_customers as suggested','suggested.acumatica_id','=','sfa_customers.suggested_acumatica_customer_id')->select(['sfa_customers.*','suggested.name as suggested_customer_name','suggested.customer_class as suggested_customer_class']);
        if($status!==''&&$status!=='all')$query->where('sfa_customers.match_status',$status);
        if($q=trim((string)$request->input('q'))) $query->where(fn($builder)=>$builder->where('sfa_customers.customer_name','like','%'.addcslashes($q,'%_\\').'%')->orWhere('sfa_customers.customer_code','like','%'.addcslashes($q,'%_\\').'%'));
        return response()->json(['counts'=>SfaCustomer::query()->select('match_status',DB::raw('COUNT(*) as total'))->groupBy('match_status')->pluck('total','match_status'),'customers'=>$query->orderByRaw("FIELD(sfa_customers.match_status,'conflict','suggested','unmatched','matched','ignored')")->orderByDesc('sfa_customers.match_score')->paginate(min(100,max(10,$request->integer('per_page',50))))]);
    }
    public function suggest(SfaCustomerMatcher $matcher): JsonResponse { return response()->json($matcher->suggest()); }
    public function confirm(Request $request,SfaCustomer $customer,SfaCustomerMatcher $matcher): JsonResponse { $data=$request->validate(['acumatica_customer_id'=>['required','string','max:50'],'notes'=>['nullable','string','max:1000']]); return response()->json($matcher->confirm($customer,$data['acumatica_customer_id'],$request->user(),$data['notes']??null)); }
    public function ignore(Request $request,SfaCustomer $customer,SfaCustomerMatcher $matcher): JsonResponse { return response()->json($matcher->setStatus($customer,'ignored',$request->user())); }
    public function unlink(Request $request,SfaCustomer $customer,SfaCustomerMatcher $matcher): JsonResponse { return response()->json($matcher->setStatus($customer,'unmatched',$request->user())); }
}
