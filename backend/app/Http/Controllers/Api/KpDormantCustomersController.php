<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\CustomerContact;

/**
 * KP Dormant Customers — KP accounts with no sales order in the last 3 calendar months
 * measured from the start of the current month (not day-of-month).
 *
 * Example (today = 2026-07-16): window starts 2026-04-01.
 * Customer is dormant if last SO date is before that window, or they have no SO at all.
 *
 * Sales Consultants only see their scoped portfolio (same as KP Accounts).
 */
class KpDormantCustomersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $settings = DB::table('kp_dormant_settings')->first();
        $months = min(max($request->integer('months', (int) ($settings->dormant_months ?? 3)), 1), 12);
        // From start of current month, go back N calendar months.
        $windowStart = Carbon::now('Africa/Nairobi')->startOfMonth()->subMonthsNoOverflow($months)->startOfDay();
        $currentMonthStart = Carbon::now('Africa/Nairobi')->startOfMonth()->toDateString();

        $q = trim((string) $request->input('q', ''));
        $perPage = min(max($request->integer('per_page', 25), 5), 100);

        // Last SO date per customer (SO type only).
        $lastSoSub = AcumaticaSalesOrder::query()
            ->select([
                'customer_acumatica_id',
                DB::raw('MAX(order_date) as last_order_date'),
                DB::raw('COUNT(*) as lifetime_order_count'),
                DB::raw('MAX(sales_consultant_rep_code) as last_rep_code'),
            ])
            ->where(function ($w) {
                $w->where('order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
                    ->orWhereNull('order_type')
                    ->orWhere('order_type', 'SO');
            })
            ->whereNotNull('customer_acumatica_id')
            ->groupBy('customer_acumatica_id');

        $base = DataScope::applyCustomerScope(
            AcumaticaCustomer::query()
                ->where('customer_class', 'like', 'KP%')
                ->when($settings?->excluded_customer_classes, fn ($query) => $query->whereNotIn('customer_class', json_decode($settings->excluded_customer_classes, true) ?: []))
                ->leftJoinSub($lastSoSub, 'so_agg', function ($join) {
                    $join->on('so_agg.customer_acumatica_id', '=', 'acumatica_customers.acumatica_id');
                })
                ->where(function ($w) use ($windowStart) {
                    $w->whereNull('so_agg.last_order_date')
                        ->orWhereDate('so_agg.last_order_date', '<', $windowStart->toDateString());
                })
                ->select([
                    'acumatica_customers.acumatica_id',
                    'acumatica_customers.name',
                    'acumatica_customers.customer_class',
                    'acumatica_customers.status',
                    'acumatica_customers.email',
                    'acumatica_customers.phone',
                    'so_agg.last_order_date',
                    'so_agg.lifetime_order_count',
                    'so_agg.last_rep_code',
                ])
                ->orderBy('acumatica_customers.name'),
            $user,
            'acumatica_customers.acumatica_id',
        );

        if ($q !== '') {
            $base->where(function ($scoped) use ($q) {
                $scoped->where('acumatica_customers.name', 'like', "%{$q}%")
                    ->orWhere('acumatica_customers.acumatica_id', 'like', "%{$q}%")
                    ->orWhere('acumatica_customers.email', 'like', "%{$q}%");
            });
        }

        $paginator = $base->paginate($perPage);
        $ids = collect($paginator->items())->pluck('acumatica_id')->filter()->values()->all();

        // Primary attachment: user_customer_assignments → user (rep_code + name).
        $assignments = $ids === []
            ? collect()
            : UserCustomerAssignment::query()
                ->whereIn('customer_acumatica_id', $ids)
                ->with(['user:id,name,email,rep_code,is_active'])
                ->orderByDesc('id')
                ->get()
                ->groupBy('customer_acumatica_id');

        // Fallback: map SO rep codes → users.
        $repCodes = collect($paginator->items())
            ->pluck('last_rep_code')
            ->filter()
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->unique()
            ->values()
            ->all();

        $usersByRep = $repCodes === []
            ? collect()
            : User::query()
                ->whereIn(DB::raw('UPPER(TRIM(rep_code))'), $repCodes)
                ->where('is_active', true)
                ->get(['id', 'name', 'email', 'rep_code'])
                ->keyBy(fn (User $u) => strtoupper(trim((string) $u->rep_code)));

        $data = collect($paginator->items())->map(function ($row) use ($assignments, $usersByRep, $windowStart) {
            $id = (string) $row->acumatica_id;
            $attached = $this->resolveAssignee(
                $assignments->get($id),
                $row->last_rep_code ? strtoupper(trim((string) $row->last_rep_code)) : null,
                $usersByRep,
            );

            $lastOrder = $row->last_order_date
                ? Carbon::parse($row->last_order_date)->toDateString()
                : null;
            $monthsIdle = null;
            if ($lastOrder) {
                $monthsIdle = (int) Carbon::parse($lastOrder)->startOfMonth()
                    ->diffInMonths(Carbon::now('Africa/Nairobi')->startOfMonth());
            }

            return [
                'acumatica_id' => $id,
                'name' => $row->name,
                'customer_class' => $row->customer_class,
                'status' => $row->status,
                'email' => $row->email,
                'phone' => $row->phone,
                'last_order_date' => $lastOrder,
                'lifetime_order_count' => (int) ($row->lifetime_order_count ?? 0),
                'months_idle' => $monthsIdle,
                'never_ordered' => $lastOrder === null,
                'assignee' => $attached,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'window' => [
                'months' => $months,
                'from' => $windowStart->toDateString(),
                'current_month_start' => $currentMonthStart,
                'label' => "No SO since {$windowStart->format('M Y')} (last {$months} calendar months from current month)",
            ],
        ]);
    }

    public function feedback(Request $request, string $customerId): JsonResponse
    {
        $this->ensureCanView($request->user());
        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customerId)) return $denied;
        $data=$request->validate(['contacted'=>'required|boolean','customer_contact_id'=>'nullable|exists:customer_contacts,id','outcome'=>'required|string|max:120','comments'=>'nullable|string|max:4000','attempted_at'=>'nullable|date']);
        if(!empty($data['customer_contact_id'])) abort_unless(CustomerContact::whereKey($data['customer_contact_id'])->where('customer_acumatica_id',$customerId)->exists(),422,'Contact does not belong to this customer.');
        $id=DB::table('kp_dormant_contact_attempts')->insertGetId($data+['customer_acumatica_id'=>$customerId,'actor_user_id'=>$request->user()->id,'attempted_at'=>$data['attempted_at']??now('Africa/Nairobi'),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(DB::table('kp_dormant_contact_attempts')->find($id),201);
    }

    public function attempts(Request $request, string $customerId): JsonResponse
    {
        $this->ensureCanView($request->user());
        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customerId)) return $denied;
        return response()->json(DB::table('kp_dormant_contact_attempts')->where('customer_acumatica_id',$customerId)->latest('attempted_at')->get());
    }

    public function handoff(Request $request, string $customerId): JsonResponse
    {
        abort_unless($request->user()->role==='Administrator'||$request->user()->hasPermission('kp.crm.dormant.manage'),403);
        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customerId)) return $denied;
        $contact=CustomerContact::active()->where('customer_acumatica_id',$customerId)->where('is_primary',true)->first();
        abort_unless($contact && ($contact->phone || $contact->email),422,'A primary contact with a phone or email is required before handoff.');
        abort_unless(DB::table('kp_dormant_contact_attempts')->where('customer_acumatica_id',$customerId)->exists(),422,'Record at least one contact attempt before handoff.');
        $id=DB::table('kp_dormant_handoffs')->insertGetId(['customer_acumatica_id'=>$customerId,'primary_contact_id'=>$contact->id,'destination'=>'calltronix','handed_off_by'=>$request->user()->id,'handed_off_at'=>now('Africa/Nairobi'),'contact_snapshot'=>json_encode($contact->toApiArray()),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(DB::table('kp_dormant_handoffs')->find($id),201);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, UserCustomerAssignment>|null  $assignmentRows
     * @param  \Illuminate\Support\Collection<string, User>  $usersByRep
     * @return array{user_id: ?int, rep_code: ?string, name: ?string, email: ?string, label: string, source: string}|null
     */
    private function resolveAssignee($assignmentRows, ?string $lastRepCode, $usersByRep): ?array
    {
        if ($assignmentRows && $assignmentRows->isNotEmpty()) {
            $primary = $assignmentRows->first(fn ($a) => $a->user && $a->user->is_active)
                ?? $assignmentRows->first();
            $u = $primary?->user;
            if ($u) {
                $rep = strtoupper(trim((string) ($u->rep_code ?? ''))) ?: null;

                return [
                    'user_id' => $u->id,
                    'rep_code' => $rep,
                    'name' => $u->name,
                    'email' => $u->email,
                    'label' => $rep ? "{$rep} — {$u->name}" : $u->name,
                    'source' => 'assignment',
                ];
            }
        }

        if ($lastRepCode) {
            $u = $usersByRep->get($lastRepCode);
            if ($u) {
                return [
                    'user_id' => $u->id,
                    'rep_code' => $lastRepCode,
                    'name' => $u->name,
                    'email' => $u->email,
                    'label' => "{$lastRepCode} — {$u->name}",
                    'source' => 'last_so_rep',
                ];
            }

            return [
                'user_id' => null,
                'rep_code' => $lastRepCode,
                'name' => null,
                'email' => null,
                'label' => $lastRepCode,
                'source' => 'last_so_rep_code_only',
            ];
        }

        return null;
    }

    private function ensureCanView(?User $user): void
    {
        if ($user === null) {
            abort(401);
        }
        if ($user->role === 'Administrator' || $user->is_super_admin) {
            return;
        }
        if ($user->hasPermission('kp.fol.view') || $user->hasPermission('kp.accounts.view')) {
            return;
        }
        abort(403, 'You do not have access to KP Dormant Customers.');
    }
}
