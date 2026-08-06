<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionPeriod;
use App\Models\CommissionRule;
use App\Models\CommissionStatement;
use App\Services\Commissions\CommissionCalculator;
use App\Services\Team\AccessTierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    public function __construct(
        private readonly CommissionCalculator $calculator,
        private readonly AccessTierService $accessTier,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['commissions.view_own','commissions.review']);
        $review = $request->user()->hasPermission('commissions.review')
            || $this->accessTier->hasUnrestrictedBusinessAccess($request->user());
        $q = CommissionStatement::with(['user:id,name,rep_code','period:id,period_month,version,status,shadow_mode']);
        if (! $review) $q->where('user_id', $request->user()->id);
        return response()->json($q->latest('id')->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, CommissionStatement $statement): JsonResponse
    {
        $this->authorizeStatement($request, $statement);
        return response()->json($statement->load(['user:id,name,rep_code','period.events','entries','adjustments']));
    }

    public function rules(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['commissions.rules.manage','commissions.review']);
        return response()->json(CommissionRule::with('tiers')->orderByDesc('effective_from')->get());
    }

    public function saveRule(Request $request, ?CommissionRule $rule = null): JsonResponse
    {
        $this->authorizeAny($request, ['commissions.rules.manage']);
        $data = $request->validate([
            'name'=>'required|string|max:255','effective_from'=>'required|date','effective_to'=>'nullable|date|after_or_equal:effective_from',
            'eligible_user_ids'=>'nullable|array','eligible_user_ids.*'=>'integer|exists:users,id',
            'eligible_customer_classes'=>'nullable|array','excluded_order_types'=>'nullable|array',
            'sales_value_basis'=>'nullable|in:order_total','cap_amount'=>'nullable|numeric|min:0','fixed_bonus'=>'nullable|numeric|min:0','is_active'=>'boolean',
            'tiers'=>'required|array|min:1','tiers.*.attainment_from_pct'=>'required|numeric|min:0',
            'tiers.*.attainment_to_pct'=>'nullable|numeric','tiers.*.commission_rate_pct'=>'required|numeric|min:0|max:100',
        ]);
        return DB::transaction(function () use ($data, $request, $rule) {
            $tiers = $data['tiers']; unset($data['tiers']);
            $data['created_by'] ??= $request->user()->id;
            $rule = $rule ? tap($rule)->update($data) : CommissionRule::create($data);
            $rule->tiers()->delete(); $rule->tiers()->createMany($tiers);
            return response()->json($rule->load('tiers'), $rule->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function saveTarget(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['commissions.rules.manage']);
        $data = $request->validate(['user_id'=>'required|exists:users,id','period_month'=>'required|date_format:Y-m','target_amount'=>'required|numeric|min:0']);
        $month = Carbon::createFromFormat('Y-m', $data['period_month'], 'Africa/Nairobi')->startOfMonth()->toDateString();
        DB::table('commission_targets')->updateOrInsert(['user_id'=>$data['user_id'],'period_month'=>$month], ['target_amount'=>$data['target_amount'],'created_by'=>$request->user()->id,'updated_at'=>now(),'created_at'=>now()]);
        return response()->json(['message'=>'Target saved.']);
    }

    public function createPeriod(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['commissions.review']);
        $data = $request->validate(['period_month'=>'required|date_format:Y-m','shadow_mode'=>'sometimes|boolean']);
        $month = Carbon::createFromFormat('Y-m', $data['period_month'], 'Africa/Nairobi')->startOfMonth();
        $version = (int) CommissionPeriod::whereDate('period_month',$month)->max('version') + 1;
        $period = CommissionPeriod::create(['period_month'=>$month,'version'=>$version,'status'=>'draft','shadow_mode'=>$data['shadow_mode'] ?? true]);
        return response()->json($this->calculator->calculate($period, $request->user()), 201);
    }

    public function recalculate(Request $request, CommissionPeriod $period): JsonResponse
    { $this->authorizeAny($request,['commissions.review']); return response()->json($this->calculator->calculate($period,$request->user())); }

    public function adjust(Request $request, CommissionStatement $statement): JsonResponse
    {
        $this->authorizeAny($request,['commissions.review']);
        abort_if($statement->period->status !== 'draft', 409, 'Only draft statements can be adjusted.');
        $data=$request->validate(['amount'=>'required|numeric','reason'=>'required|string|min:3|max:2000']);
        $statement->adjustments()->create($data+['created_by'=>$request->user()->id]);
        $sum=(float)$statement->adjustments()->sum('amount');
        $statement->update(['adjustment_amount'=>$sum,'net_commission'=>(float)$statement->gross_commission+$sum]);
        $statement->period->events()->create(['event_type'=>'adjusted','actor_user_id'=>$request->user()->id,'payload'=>['statement_id'=>$statement->id,'amount'=>(float)$data['amount'],'reason'=>$data['reason']]]);
        return response()->json($statement->fresh(['adjustments']));
    }

    public function transition(Request $request, CommissionPeriod $period): JsonResponse
    {
        $data=$request->validate(['action'=>'required|in:approve,lock,reverse','reason'=>'required_if:action,reverse|nullable|string|min:3']);
        $permission=$data['action']==='approve'?'commissions.approve':'commissions.lock'; $this->authorizeAny($request,[$permission]);
        return DB::transaction(function () use ($period,$data,$request) {
            $now=now('Africa/Nairobi');
            if($data['action']==='approve'){ abort_if($period->status!=='draft',409,'Only drafts can be approved.'); $period->update(['status'=>'approved','approved_at'=>$now,'approved_by'=>$request->user()->id]); }
            elseif($data['action']==='lock'){ abort_if($period->status!=='approved',409,'Approve the period before locking.'); $period->update(['status'=>'locked','locked_at'=>$now,'locked_by'=>$request->user()->id]); }
            else { abort_if($period->status!=='locked',409,'Only locked periods can be reversed.'); $period->update(['status'=>'reversed']); $replacement=CommissionPeriod::create(['period_month'=>$period->period_month,'version'=>$period->version+1,'status'=>'draft','shadow_mode'=>$period->shadow_mode,'reverses_period_id'=>$period->id,'reversal_reason'=>$data['reason']]); $period->events()->create(['event_type'=>'reversed','actor_user_id'=>$request->user()->id,'payload'=>['replacement_period_id'=>$replacement->id,'reason'=>$data['reason']]]); return response()->json($this->calculator->calculate($replacement,$request->user())); }
            $period->events()->create(['event_type'=>$data['action'].'d','actor_user_id'=>$request->user()->id]); return response()->json($period->fresh('events'));
        });
    }

    private function authorizeStatement(Request $request, CommissionStatement $statement): void
    { if($request->isMethod('GET') && $this->accessTier->hasUnrestrictedBusinessAccess($request->user())) return; if($statement->user_id===$request->user()->id && $request->user()->hasPermission('commissions.view_own')) return; $this->authorizeAny($request,['commissions.review']); }
    private function authorizeAny(Request $request, array $permissions): void
    { if($request->isMethod('GET') && $this->accessTier->hasUnrestrictedBusinessAccess($request->user())) return; abort_unless(collect($permissions)->contains(fn($p)=>$request->user()->hasPermission($p)),403); }
}
