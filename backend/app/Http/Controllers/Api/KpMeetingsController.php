<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\KpMeeting;
use App\Models\KpMeetingAction;
use App\Models\KpMeetingActionCategory;
use App\Models\KpMeetingParticipant;
use App\Models\KpMeetingPurpose;
use App\Models\KpMeetingTarget;
use App\Models\User;
use App\Services\Team\OrgScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Meetings are intentionally cross-segment. The KP navigation label does not impose a KP-only
 * customer-class rule; OrgScope/DataScope remains the access boundary.
 */
class KpMeetingsController extends Controller
{
    public function __construct(private readonly OrgScopeService $scope) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        [$start, $end, $month] = $this->monthRange($request->input('month'));
        $query = $this->visibleQuery($user)->with($this->relations())->orderBy('starts_at');
        $query->whereBetween('starts_at', [$start, $end]);
        $this->applyFilters($query, $request);
        return response()->json([
            ...$query->paginate(min(max($request->integer('per_page', 50), 5), 100))->toArray(),
            'month' => $month,
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        [$start, $end, $month] = $this->monthRange($request->input('month'));
        $query = $this->visibleQuery($user)->whereBetween('starts_at', [$start, $end]);
        $this->applyFilters($query, $request);
        $meetings = $query->with($this->relations())->get()->unique('id')->values();
        $ownerId = $request->integer('owner_user_id') ?: $user->id;
        if (! in_array($ownerId, $this->scopeUserIds($user), true)) abort(403, 'Consultant is outside your scope.');
        $workingDays = collect(range(0, $start->diffInDays($end)))->map(fn ($i) => $start->copy()->addDays($i))->filter(fn ($d) => $d->isWeekday())->count();
        $target = KpMeetingTarget::query()->where('user_id', $ownerId)->whereDate('month', $start->toDateString())->value('target') ?? $workingDays * 4;
        $owned = $meetings->where('owner_user_id', $ownerId);
        $completed = $owned->where('status', 'completed');
        $planned = $owned->where('is_planned', true);
        $completedPlanned = $planned->where('status', 'completed')->count();
        $pastScheduled = $planned->where('status', 'scheduled')->filter(fn ($m) => $m->starts_at->isPast())->count();
        $purposeSplit = $meetings->groupBy(fn ($m) => $m->purpose?->name ?? 'Uncategorised')->map->count()->sortDesc();
        $mainActions = KpMeetingActionCategory::query()->where('is_main', true)->orderBy('sort_order')->limit(4)->get()->map(function ($category) use ($meetings) {
            $actions = $meetings->flatMap->actions->where('category_id', $category->id);
            return ['id'=>$category->id,'name'=>$category->name,'open'=>$actions->where('status','open')->count(),'closed'=>$actions->where('status','completed')->count()];
        });
        $customerGroups = $completed->whereNotNull('customer_acumatica_id')->groupBy('customer_acumatica_id');
        $customerIds = $customerGroups->keys();
        $orders = AcumaticaSalesOrder::query()->whereIn('customer_acumatica_id', $customerIds)
            ->select('customer_acumatica_id', DB::raw('COUNT(CASE WHEN order_date BETWEEN ? AND ? THEN 1 END) as month_order_count'), DB::raw('SUM(CASE WHEN order_date BETWEEN ? AND ? THEN order_total ELSE 0 END) as month_order_value'), DB::raw('MAX(order_date) as last_order_date'))
            ->addBinding([$start, $end, $start, $end], 'select')->groupBy('customer_acumatica_id')->get()->keyBy('customer_acumatica_id');
        $patterns = $customerGroups->map(function ($visits, $id) use ($orders) {
            $order = $orders->get($id);
            return ['customer_acumatica_id'=>$id,'customer_name'=>$visits->first()->customer_name,'visits'=>$visits->count(),'repeat_visits'=>max(0,$visits->count()-1),'last_visit'=>$visits->max('starts_at')?->toDateString(),'next_follow_up'=>$visits->whereNotNull('follow_up_date')->min('follow_up_date')?->toDateString(),'month_order_count'=>(int)($order?->month_order_count ?? 0),'month_order_value'=>(float)($order?->month_order_value ?? 0),'last_order_date'=>$order?->last_order_date];
        })->sortByDesc('visits')->values();
        $rankings = $meetings->groupBy('owner_user_id')->map(function ($rows) {
            $owner = $rows->first()->owner;
            return ['user_id'=>$owner?->id,'name'=>$owner?->name ?? 'Unknown','completed'=>$rows->where('status','completed')->count(),'planned'=>$rows->where('is_planned',true)->count()];
        })->sortByDesc('completed')->values();
        return response()->json([
            'month'=>$month,'scope_user_ids'=>$this->scopeUserIds($user),
            'metrics'=>['target'=>(int)$target,'achieved'=>$completed->count(),'total'=>$owned->count(),'unique_customers'=>$customerGroups->count(),'repeat_visits'=>$customerGroups->sum(fn($v)=>max(0,$v->count()-1)),'planned'=>$planned->count(),'completed_planned'=>$completedPlanned,'missed'=>$pastScheduled,'cancelled'=>$planned->where('status','cancelled')->count(),'unplanned'=>$owned->where('is_planned',false)->count(),'adherence_percent'=>$planned->count() ? round($completedPlanned/$planned->count()*100,1) : 0],
            'purpose_split'=>$purposeSplit->map(fn($count,$name)=>['name'=>$name,'count'=>$count])->values(),
            'main_actions'=>$mainActions,'purchase_patterns'=>$patterns,'rankings'=>$rankings,
            'overdue_actions'=>$meetings->flatMap->actions->where('status','open')->filter(fn($a)=>$a->due_date && $a->due_date->isPast())->values(),
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $ids = $this->scopeUserIds($user);
        return response()->json([
            'purposes'=>KpMeetingPurpose::query()->orderBy('sort_order')->get(),
            'action_categories'=>KpMeetingActionCategory::query()->orderBy('sort_order')->get(),
            'consultants'=>User::query()->whereIn('id',$ids)->where('is_active',true)->get(['id','name','email','role','org_level']),
            'is_admin'=>$this->isAdmin($user),
            'current_user_id'=>$user->id,
        ]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $q = trim((string) $request->input('q', ''));
        $query = AcumaticaCustomer::query()->orderBy('name');
        $this->scope->applyCustomerScope($query, $user);
        if ($q !== '') {
            $query->where(function ($scoped) use ($q) {
                $scoped->where('name', 'like', "%{$q}%")->orWhere('acumatica_id', 'like', "%{$q}%");
            });
        }
        $customers = $query->limit(25)->get(['acumatica_id','name','customer_class','status','email','phone','payment_terms','billing_address']);
        $ids = $customers->pluck('acumatica_id');
        $lastMeetings = KpMeeting::query()->whereIn('customer_acumatica_id', $ids)
            ->orderByDesc('starts_at')
            ->get(['customer_acumatica_id','starts_at','title','owner_user_id','status'])
            ->unique('customer_acumatica_id')->keyBy('customer_acumatica_id');
        $owners = User::query()->whereIn('id', $lastMeetings->pluck('owner_user_id')->filter()->unique())->pluck('name', 'id');
        return response()->json($customers->map(function ($c) use ($lastMeetings, $owners) {
            $last = $lastMeetings->get($c->acumatica_id);
            $address = $c->billing_address ?? [];
            return [
                'acumatica_id'=>$c->acumatica_id,'name'=>$c->name,'customer_class'=>$c->customer_class,'status'=>$c->status,
                'email'=>$c->email,'phone'=>$c->phone,'payment_terms'=>$c->payment_terms,
                'location'=>implode(', ', array_filter([$address['city'] ?? null, $address['state'] ?? null, $address['country'] ?? null])) ?: null,
                'last_meeting'=>$last ? ['starts_at'=>$last->starts_at,'title'=>$last->title,'status'=>$last->status,'owner_name'=>$owners->get($last->owner_user_id)] : null,
            ];
        }));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $data = $this->validateMeeting($request);
        $ownerId = (int)($data['owner_user_id'] ?? $user->id);
        if (!in_array($ownerId,$this->scopeUserIds($user),true)) abort(403,'Owner is outside your scope.');
        $this->validateCustomer($user, $data);
        $meeting = DB::transaction(function () use ($data,$user,$ownerId) {
            $actions=$data['actions']??[]; $participants=$data['participant_user_ids']??[];
            unset($data['actions'],$data['participant_user_ids'],$data['owner_user_id']);
            $meeting=KpMeeting::create([...$data,'notes'=>$data['current_notes']??null,'created_by'=>$user->id,'owner_user_id'=>$ownerId]);
            $this->syncActions($meeting,$actions,$user); $this->syncParticipants($meeting,$participants,$user);
            return $meeting;
        });
        return response()->json($meeting->load($this->relations()),201);
    }

    public function update(Request $request, KpMeeting $meeting): JsonResponse
    {
        $user=$this->actor($request); $this->ensureCanEdit($user,$meeting);
        $data=$this->validateMeeting($request,true,$meeting);
        $customerData = array_merge($meeting->only(['is_internal','customer_acumatica_id','customer_name']), $data);
        $this->validateCustomer($user,$customerData);
        if (array_key_exists('is_internal', $data) || array_key_exists('customer_acumatica_id', $data)) {
            $data['customer_acumatica_id'] = $customerData['customer_acumatica_id'];
            $data['customer_name'] = $customerData['customer_name'];
        }
        DB::transaction(function() use($data,$meeting,$user){
            $actions=$data['actions']??null; $participants=$data['participant_user_ids']??null;
            unset($data['actions'],$data['participant_user_ids'],$data['owner_user_id']);
            if(($data['status']??$meeting->status)==='completed'){
                $notes=trim((string)($data['current_notes']??$meeting->current_notes)); $outcome=trim((string)($data['outcome']??$meeting->outcome));
                $follow=$data['follow_up_date']??$meeting->follow_up_date; $reason=trim((string)($data['no_follow_up_reason']??$meeting->no_follow_up_reason));
                if($notes===''||$outcome===''||(!$follow&&$reason==='')) abort(422,'Current notes, outcome, and a follow-up date or no-follow-up reason are required to complete a meeting.');
                $data['completed_at']=$meeting->completed_at??now();
            }
            if(($data['status']??null)==='cancelled') $data['cancelled_at']=$meeting->cancelled_at??now();
            if(array_key_exists('current_notes',$data)) $data['notes']=$data['current_notes'];
            $meeting->update($data);
            if($actions!==null) $this->syncActions($meeting,$actions,$user);
            if($participants!==null) $this->syncParticipants($meeting,$participants,$user);
        });
        return response()->json($meeting->fresh()->load($this->relations()));
    }

    public function updateAction(Request $request, KpMeetingAction $action): JsonResponse
    {
        $user=$this->actor($request); $meeting=$action->meeting;
        if(!$this->canEdit($user,$meeting) && (int)$action->owner_user_id!==$user->id) abort(403);
        $data=$request->validate(['description'=>['sometimes','string','max:3000'],'due_date'=>['nullable','date'],'status'=>['sometimes',Rule::in(['open','completed'])]]);
        if(($data['status']??null)==='completed') $data['completed_at']=now();
        if(($data['status']??null)==='open') $data['completed_at']=null;
        $action->update($data); return response()->json($action->fresh()->load('owner:id,name'));
    }

    public function respond(Request $request, KpMeetingParticipant $participant): JsonResponse
    {
        $user=$this->actor($request); if((int)$participant->user_id!==$user->id) abort(403);
        $data=$request->validate(['status'=>['required',Rule::in(['accepted','declined'])]]);
        $participant->update([...$data,'responded_at'=>now()]); return response()->json($participant->fresh());
    }

    public function saveTarget(Request $request): JsonResponse
    {
        $user=$this->actor($request); if(!$this->isAdmin($user)) abort(403);
        $data=$request->validate(['user_id'=>['required','integer','exists:users,id'],'month'=>['required','date_format:Y-m'],'target'=>['required','integer','min:0','max:500']]);
        $month=Carbon::createFromFormat('Y-m',$data['month'],'Africa/Nairobi')->startOfMonth();
        return response()->json(KpMeetingTarget::updateOrCreate(['user_id'=>$data['user_id'],'month'=>$month->toDateString()],['target'=>$data['target'],'set_by'=>$user->id]));
    }

    public function savePurpose(Request $request, ?KpMeetingPurpose $purpose=null): JsonResponse
    {
        $user=$this->actor($request); if(!$this->isAdmin($user)) abort(403);
        $data=$request->validate(['name'=>['required','string','max:150',Rule::unique('kp_meeting_purposes')->ignore($purpose?->id)],'allows_internal'=>['boolean'],'is_active'=>['boolean'],'sort_order'=>['integer','min:0']]);
        $purpose ? $purpose->update($data) : $purpose=KpMeetingPurpose::create($data);
        return response()->json($purpose,$purpose->wasRecentlyCreated?201:200);
    }

    public function saveActionCategory(Request $request, ?KpMeetingActionCategory $category=null): JsonResponse
    {
        $user=$this->actor($request); if(!$this->isAdmin($user)) abort(403);
        $data=$request->validate(['name'=>['required','string','max:120',Rule::unique('kp_meeting_action_categories')->ignore($category?->id)],'is_main'=>['boolean'],'is_active'=>['boolean'],'sort_order'=>['integer','min:0']]);
        if(($data['is_main']??false) && KpMeetingActionCategory::where('is_main',true)->when($category,fn($q)=>$q->whereKeyNot($category->id))->count()>=4) abort(422,'Only four action categories can be marked as main.');
        $category ? $category->update($data) : $category=KpMeetingActionCategory::create($data);
        return response()->json($category,$category->wasRecentlyCreated?201:200);
    }

    public function destroy(Request $request,KpMeeting $meeting): JsonResponse { $user=$this->actor($request);$this->ensureCanEdit($user,$meeting);$meeting->delete();return response()->json(['message'=>'Meeting deleted.']); }

    private function validateMeeting(Request $request,bool $partial=false,?KpMeeting $meeting=null): array
    {
        $sometimes=$partial?'sometimes':'required';
        return $request->validate([
            'title'=>[$sometimes,'string','max:255'],'purpose_id'=>[$sometimes,'integer','exists:kp_meeting_purposes,id'],
            'meeting_mode'=>[$sometimes,Rule::in(['virtual','physical'])],'is_internal'=>['boolean'],'is_planned'=>['boolean'],
            'customer_acumatica_id'=>['nullable','string','max:50'],'customer_name'=>['nullable','string','max:255'],
            'starts_at'=>[$sometimes,'date'],'ends_at'=>['nullable','date','after_or_equal:starts_at'],'location'=>['nullable','string','max:255'],
            'previous_notes'=>['nullable','string','max:5000'],'current_notes'=>['nullable','string','max:5000'],'outcome'=>['nullable','string','max:5000'],
            'follow_up_date'=>['nullable','date'],'no_follow_up_reason'=>['nullable','string','max:500'],'status'=>['sometimes',Rule::in(['scheduled','completed','cancelled'])],
            'owner_user_id'=>['sometimes','integer','exists:users,id'],'participant_user_ids'=>['array'],'participant_user_ids.*'=>['integer','distinct','exists:users,id'],
            'actions'=>['array'],'actions.*.category_id'=>['nullable','integer','exists:kp_meeting_action_categories,id'],'actions.*.description'=>['required','string','max:3000'],'actions.*.owner_user_id'=>['nullable','integer','exists:users,id'],'actions.*.due_date'=>['nullable','date'],'actions.*.status'=>['sometimes',Rule::in(['open','completed'])],
            'b2b_details'=>['nullable','array'], 'b2b_details.*'=>['nullable','string','max:2000'],
        ]);
    }

    private function validateCustomer(User $user,array &$data): void
    {
        if(($data['is_internal']??false)===true){$data['customer_acumatica_id']=null;$data['customer_name']=null;return;}
        $id=trim((string)($data['customer_acumatica_id']??'')); if($id==='') abort(422,'Select an assigned customer or mark the meeting internal.');
        if(!$this->scope->customerAccessible($user,$id)) abort(403,'Customer is outside your assigned scope.');
        $customer=AcumaticaCustomer::where('acumatica_id',$id)->firstOrFail(); $data['customer_name']=$customer->name;
    }

    private function syncActions(KpMeeting $meeting,array $actions,User $user): void
    {
        $meeting->actions()->delete(); foreach($actions as $row){$category=!empty($row['category_id'])?KpMeetingActionCategory::find($row['category_id']):null;$meeting->actions()->create([...$row,'owner_user_id'=>$row['owner_user_id']??$meeting->owner_user_id,'category_snapshot'=>$category?->name??'Other','completed_at'=>($row['status']??'open')==='completed'?now():null]);}
    }
    private function syncParticipants(KpMeeting $meeting,array $ids,User $user): void
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id!==$meeting->owner_user_id)));
        $meeting->participants()->whereNotIn('user_id',$ids ?: [0])->delete(); foreach($ids as $id)$meeting->participants()->firstOrCreate(['user_id'=>$id],['role'=>'accompanier','status'=>'pending','invited_by'=>$user->id]);
    }
    private function relations(): array { return ['purpose','owner:id,name,email,rep_code','creator:id,name,email','participants.user:id,name,email','actions.owner:id,name,email','actions.category']; }
    private function actor(Request $request): User { $user=$request->user();if(!$user)abort(401);if(!$this->isAdmin($user)&&!$user->hasPermission('kp.fol.view')&&!$user->hasPermission('kp.accounts.view'))abort(403,'You do not have access to KP Meetings.');return $user; }
    private function scopeUserIds(User $user): array { return $this->isAdmin($user)||$this->scope->hasOrgWideAccess($user)?User::where('is_active',true)->pluck('id')->map(fn($id)=>(int)$id)->all():$this->scope->effectiveScopeUserIds($user); }
    private function visibleQuery(User $user): Builder { $ids=$this->scopeUserIds($user);return KpMeeting::query()->where(function($q)use($ids,$user){$q->whereIn('owner_user_id',$ids)->orWhereHas('participants',fn($p)=>$p->where('user_id',$user->id)->whereIn('status',['pending','accepted']));}); }
    private function applyFilters(Builder $q,Request $r): void { if($r->filled('owner_user_id'))$q->where('owner_user_id',$r->integer('owner_user_id'));if($r->filled('status'))$q->where('status',$r->input('status'));if($r->filled('purpose_id'))$q->where('purpose_id',$r->integer('purpose_id'));if($r->filled('meeting_mode'))$q->where('meeting_mode',$r->input('meeting_mode'));if($r->filled('q')){$s=trim($r->input('q'));$q->where(fn($w)=>$w->where('title','like',"%$s%")->orWhere('customer_name','like',"%$s%"));} }
    private function monthRange(?string $month): array { try{$start=Carbon::createFromFormat('Y-m',$month?:Carbon::now('Africa/Nairobi')->format('Y-m'),'Africa/Nairobi')->startOfMonth();}catch(Throwable){$start=Carbon::now('Africa/Nairobi')->startOfMonth();}$end=$start->copy()->endOfMonth();return[$start,$end,$start->format('Y-m')]; }
    private function isAdmin(User $u): bool { return $u->role==='Administrator'||(bool)$u->is_super_admin; }
    private function canEdit(User $u,KpMeeting $m): bool { return $this->isAdmin($u)||(int)$m->owner_user_id===$u->id||(int)$m->created_by===$u->id; }
    private function ensureCanEdit(User $u,KpMeeting $m): void { if(!$this->canEdit($u,$m))abort(403,'You can only edit your own meetings.'); }
}
