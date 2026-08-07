<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\KpActivityQuestionnaire;
use App\Models\KpMeeting;
use App\Models\KpMeetingAction;
use App\Models\KpMeetingActionCategory;
use App\Models\KpMeetingParticipant;
use App\Models\KpMeetingPurpose;
use App\Models\KpMeetingTarget;
use App\Models\User;
use App\Services\Team\OrgScopeService;
use App\Services\Team\UserCapabilitiesService;
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
    public function __construct(
        private readonly OrgScopeService $scope,
        private readonly UserCapabilitiesService $capabilities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        [$start, $end, $month] = $this->monthRange($request->input('month'));
        $query = $this->visibleQuery($user)->with($this->relations())->orderBy('starts_at');
        if (! $request->routeIs('kp.activities.*')) $query->where('activity_type', 'meeting');
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
        if (! $request->routeIs('kp.activities.*')) $query->where('activity_type', 'meeting');
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
            'questionnaires'=>KpActivityQuestionnaire::query()->where('is_active',true)->with('purpose:id,name')->orderBy('activity_type')->orderByDesc('version')->get()->unique(fn($q)=>($q->purpose_id??'all').':'.$q->activity_type)->values(),
            'action_categories'=>KpMeetingActionCategory::query()->orderBy('sort_order')->get(),
            'consultants'=>User::query()->whereIn('id',$ids)->where('is_active',true)->get(['id','name','email','role','org_level']),
            'departments'=>DB::table('departments')->whereIn('id',User::query()->whereIn('id',$ids)->whereNotNull('department_id')->select('department_id'))->orderBy('name')->get(['id','name','slug']),
            'sectors'=>DB::table('user_sector_scopes')->whereIn('user_id',$ids)->distinct()->orderBy('sector')->pluck('sector')->values(),
            'is_admin'=>$this->isAdmin($user),
            'allowed_scopes'=>$this->allowedScopes($user),
            'can_edit'=>$this->canWriteActivities($user),
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
        if (! $request->routeIs('kp.activities.*')) $data['activity_type'] = 'meeting';
        elseif (empty($data['activity_type'])) abort(422, 'Select an activity type.');
        if (! $this->canWriteActivities($user)) abort(403, 'Executive activity access is read-only.');
        $this->validatePurposeType($data);
        $this->validateQuestionnaire($data);
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
        $user=$this->actor($request); if (! $this->canWriteActivities($user)) abort(403, 'Executive activity access is read-only.'); $this->ensureCanEdit($user,$meeting);
        $data=$this->validateMeeting($request,true,$meeting);
        $this->validatePurposeType(array_merge($meeting->toArray(),$data));
        $questionnaireData = array_merge($meeting->toArray(), $data);
        $this->validateQuestionnaire($questionnaireData);
        if (array_key_exists('questionnaire_version', $questionnaireData)) $data['questionnaire_version'] = $questionnaireData['questionnaire_version'];
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
        if (! $this->canWriteActivities($user)) abort(403, 'Executive activity access is read-only.');
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
        $data=$request->validate(['name'=>['required','string','max:150',Rule::unique('kp_meeting_purposes')->ignore($purpose?->id)],'activity_types'=>['sometimes','array','min:1'],'activity_types.*'=>[Rule::in(['phone','email','meeting','visit'])],'allows_internal'=>['boolean'],'customer_required'=>['boolean'],'is_active'=>['boolean'],'sort_order'=>['integer','min:0']]);
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

    public function destroy(Request $request,KpMeeting $meeting): JsonResponse { $user=$this->actor($request);if(!$this->canWriteActivities($user))abort(403,'Executive activity access is read-only.');$this->ensureCanEdit($user,$meeting);$meeting->delete();return response()->json(['message'=>'Activity deleted.']); }

    public function followUps(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $today = Carbon::now('Africa/Nairobi')->startOfDay();
        $actions = KpMeetingAction::query()
            ->whereHas('meeting', fn (Builder $q) => $this->applyVisibleConstraint($q, $user))
            ->with(['meeting:id,title,activity_type,customer_name,starts_at,owner_user_id', 'owner:id,name,email', 'category'])
            ->when($request->filled('owner_user_id'), fn ($q) => $q->where('owner_user_id', $request->integer('owner_user_id')))
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->get();
        $open = $actions->where('status', 'open');
        return response()->json([
            'overdue' => $open->filter(fn ($a) => $a->due_date?->lt($today))->values(),
            'due_today' => $open->filter(fn ($a) => $a->due_date?->isSameDay($today))->values(),
            'upcoming' => $open->filter(fn ($a) => $a->due_date?->gt($today))->values(),
            'completed' => $actions->where('status', 'completed')->values(),
        ]);
    }

    public function activityDashboard(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        [$start, $end, $month] = $this->monthRange($request->input('month'));
        $scope = (string) $request->input('scope', 'self');
        if (! in_array($scope, $this->allowedScopes($user), true)) abort(403, 'Dashboard scope is not available to you.');
        $query = KpMeeting::query()->whereBetween('starts_at', [$start, $end]);
        $this->applyScope($query, $user, $scope);
        $this->applyFilters($query, $request);
        $rows = $query->with(['owner:id,name,department_id,org_level', 'owner.department:id,name', 'purpose:id,name', 'actions'])->get();
        $planned = $rows->where('is_planned', true);
        $completedPlanned = $planned->where('status', 'completed')->count();
        $missed = $planned->where('status', 'scheduled')->filter(fn ($m) => $m->starts_at->isPast())->count();
        $overdue = $rows->flatMap->actions->where('status', 'open')->filter(fn ($a) => $a->due_date?->isPast())->count();
        $summary = fn ($group) => [
            'total' => $group->count(), 'planned' => $group->where('is_planned', true)->count(),
            'completed' => $group->where('status', 'completed')->count(),
            'missed' => $group->where('is_planned', true)->where('status', 'scheduled')->filter(fn ($m) => $m->starts_at->isPast())->count(),
            'cancelled' => $group->where('status', 'cancelled')->count(), 'unplanned' => $group->where('is_planned', false)->count(),
            'adherence_percent' => $group->where('is_planned', true)->count() ? round($group->where('is_planned', true)->where('status', 'completed')->count() / $group->where('is_planned', true)->count() * 100, 1) : 0,
        ];
        return response()->json([
            'month' => $month, 'scope' => $scope, 'allowed_scopes' => $this->allowedScopes($user),
            'metrics' => [...$summary($rows), 'active_consultants' => $rows->pluck('owner_user_id')->filter()->unique()->count(), 'customers_engaged' => $rows->where('status','completed')->pluck('customer_acumatica_id')->filter()->unique()->count(), 'overdue_follow_ups' => $overdue],
            'activity_split' => $rows->groupBy('activity_type')->map(fn ($g,$name) => ['name'=>$name,'count'=>$g->count()])->values(),
            'purpose_split' => $rows->groupBy(fn($m)=>$m->purpose?->name ?? 'Uncategorised')->map(fn($g,$name)=>['name'=>$name,'count'=>$g->count()])->values(),
            'consultants' => $rows->groupBy('owner_user_id')->map(fn($g)=>['user_id'=>$g->first()->owner_user_id,'name'=>$g->first()->owner?->name ?? 'Unknown',...$summary($g)])->values(),
            'departments' => $rows->groupBy(fn($m)=>$m->owner?->primaryDepartment()?->name ?? 'Unassigned')->map(fn($g,$name)=>['name'=>$name,...$summary($g)])->values(),
        ]);
    }

    public function saveQuestionnaire(Request $request): JsonResponse
    {
        $user = $this->actor($request); if (! $this->isAdmin($user)) abort(403);
        $data = $request->validate([
            'purpose_id'=>['nullable','integer','exists:kp_meeting_purposes,id'], 'activity_type'=>['required',Rule::in(['phone','email','meeting','visit'])],
            'questions'=>['required','array','max:30'], 'questions.*.key'=>['required','string','max:80'], 'questions.*.label'=>['required','string','max:180'],
            'questions.*.type'=>['required',Rule::in(['text','select','multi_select','number','date','boolean'])], 'questions.*.required'=>['boolean'], 'questions.*.options'=>['sometimes','array','max:30'],
        ]);
        $version = (int) KpActivityQuestionnaire::query()->where('purpose_id',$data['purpose_id']??null)->where('activity_type',$data['activity_type'])->max('version') + 1;
        KpActivityQuestionnaire::query()->where('purpose_id',$data['purpose_id']??null)->where('activity_type',$data['activity_type'])->update(['is_active'=>false]);
        return response()->json(KpActivityQuestionnaire::create([...$data,'version'=>$version,'is_active'=>true,'created_by'=>$user->id]), 201);
    }

    private function validateMeeting(Request $request,bool $partial=false,?KpMeeting $meeting=null): array
    {
        $sometimes=$partial?'sometimes':'required';
        return $request->validate([
            'title'=>[$sometimes,'string','max:255'],'activity_type'=>['sometimes',Rule::in(['phone','email','meeting','visit'])],'purpose_id'=>[$sometimes,'integer','exists:kp_meeting_purposes,id'],
            'questionnaire_id'=>['nullable','integer','exists:kp_activity_questionnaires,id'],'questionnaire_answers'=>['nullable','array'],
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
    private function relations(): array { return ['purpose','questionnaire','owner:id,name,email,rep_code,department_id,org_level,department_role','creator:id,name,email','participants.user:id,name,email','actions.owner:id,name,email','actions.category']; }
    private function actor(Request $request): User { $user=$request->user();if(!$user)abort(401);if(!$this->isAdmin($user)&&!$this->isExecutive($user)&&!$user->hasPermission('kp.fol.view')&&!$user->hasPermission('kp.accounts.view'))abort(403,'You do not have access to KP Activities.');return $user; }
    private function scopeUserIds(User $user): array { return $this->isAdmin($user)||$this->isExecutive($user)||$this->scope->hasOrgWideAccess($user)?User::where('is_active',true)->pluck('id')->map(fn($id)=>(int)$id)->all():$this->scope->effectiveScopeUserIds($user); }
    private function visibleQuery(User $user): Builder { $q=KpMeeting::query();$this->applyVisibleConstraint($q,$user);return $q; }
    private function applyVisibleConstraint(Builder $q, User $user): Builder { $ids=$this->scopeUserIds($user);return $q->where(function($w)use($ids,$user){$w->whereIn('owner_user_id',$ids)->orWhereHas('participants',fn($p)=>$p->where('user_id',$user->id)->whereIn('status',['pending','accepted']));}); }
    private function applyFilters(Builder $q,Request $r): void { if($r->filled('owner_user_id'))$q->where('owner_user_id',$r->integer('owner_user_id'));if($r->filled('status'))$q->where('status',$r->input('status'));if($r->filled('purpose_id'))$q->where('purpose_id',$r->integer('purpose_id'));if($r->filled('activity_type'))$q->where('activity_type',$r->input('activity_type'));if($r->filled('meeting_mode'))$q->where('meeting_mode',$r->input('meeting_mode'));if($r->filled('customer_acumatica_id'))$q->where('customer_acumatica_id',$r->input('customer_acumatica_id'));if($r->filled('department_id'))$q->whereHas('owner',fn($u)=>$u->where('department_id',$r->integer('department_id')));if($r->filled('sector'))$q->whereHas('owner.sectorScopes',fn($s)=>$s->where('sector',$r->input('sector')));if($r->filled('from'))$q->where('starts_at','>=',$r->date('from')->startOfDay());if($r->filled('to'))$q->where('starts_at','<=',$r->date('to')->endOfDay());if($r->filled('q')){$s=trim($r->input('q'));$q->where(fn($w)=>$w->where('title','like',"%$s%")->orWhere('customer_name','like',"%$s%"));} }
    private function allowedScopes(User $user): array { if($this->isAdmin($user)||$this->isExecutive($user))return ['self','team','department','organization'];$ids=$this->scope->effectiveScopeUserIds($user);return count($ids)>1?['self','team','department']:['self']; }
    private function applyScope(Builder $q,User $user,string $scope): void { if($scope==='self'){$q->where('owner_user_id',$user->id);return;}if($scope==='organization'&&($this->isAdmin($user)||$this->isExecutive($user)))return;$q->whereIn('owner_user_id',$this->scope->effectiveScopeUserIds($user)); }
    private function isExecutive(User $user): bool { return in_array(strtolower((string)$user->org_level),['executive','c_suite'],true) && (bool)($this->capabilities->forUser($user)['executive_view']??false); }
    private function canWriteActivities(User $user): bool { return !$this->isExecutive($user)||$this->isAdmin($user); }
    private function validatePurposeType(array $data): void { $purpose=KpMeetingPurpose::find($data['purpose_id']??null);$types=$purpose?->activity_types??[];if($types!==[]&&!in_array($data['activity_type']??'meeting',$types,true))abort(422,'The selected purpose is not available for this activity type.'); }
    private function validateQuestionnaire(array &$data): void { if(empty($data['questionnaire_id']))return;$q=KpActivityQuestionnaire::findOrFail($data['questionnaire_id']);if($q->activity_type!==($data['activity_type']??'meeting')||($q->purpose_id&&$q->purpose_id!==(int)($data['purpose_id']??0)))abort(422,'The questionnaire does not match the selected activity.');$data['questionnaire_version']=$q->version;if(($data['status']??'scheduled')!=='completed')return;$answers=$data['questionnaire_answers']??[];foreach($q->questions as $question){if(($question['required']??false)&&(!array_key_exists($question['key'],$answers)||$answers[$question['key']]===null||$answers[$question['key']]===''))abort(422,"Answer {$question['label']} before completing this activity.");} }
    private function monthRange(?string $month): array { try{$start=Carbon::createFromFormat('Y-m',$month?:Carbon::now('Africa/Nairobi')->format('Y-m'),'Africa/Nairobi')->startOfMonth();}catch(Throwable){$start=Carbon::now('Africa/Nairobi')->startOfMonth();}$end=$start->copy()->endOfMonth();return[$start,$end,$start->format('Y-m')]; }
    private function isAdmin(User $u): bool { return $u->role==='Administrator'||(bool)$u->is_super_admin; }
    private function canEdit(User $u,KpMeeting $m): bool { return $this->isAdmin($u)||(int)$m->owner_user_id===$u->id||(int)$m->created_by===$u->id; }
    private function ensureCanEdit(User $u,KpMeeting $m): void { if(!$this->canEdit($u,$m))abort(403,'You can only edit your own meetings.'); }
}
