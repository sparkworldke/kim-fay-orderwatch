<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerAssignmentBatch;
use App\Models\User;
use App\Services\Team\BrandFilterService;
use App\Services\Team\CustomerAssignmentService;
use App\Models\AcumaticaCustomer;
use App\Models\UserBrandAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAssignmentController extends Controller
{
    public function brandOptions(BrandFilterService $brandFilter): JsonResponse
    {
        $hierarchy = $brandFilter->hierarchyOptions();
        $trading = collect($hierarchy)->firstWhere('key', 'trading');
        $brands = collect($trading['brands'] ?? [])->pluck('brand')->filter()->values();

        return response()->json([
            'partner_brands' => $brands,
            'hierarchy' => $hierarchy,
        ]);
    }

    public function brandAssignments(User $user): JsonResponse
    {
        return response()->json(
            $user->brandAssignments()->orderBy('brand')->get(['id', 'brand', 'created_at']),
        );
    }

    public function syncBrandAssignments(User $user, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brands' => ['required', 'array'],
            'brands.*' => ['string', 'max:100'],
        ]);

        $brands = array_values(array_unique(array_filter(array_map(
            fn ($b) => trim((string) $b),
            $validated['brands'],
        ))));

        UserBrandAssignment::query()->where('user_id', $user->id)->delete();

        foreach ($brands as $brand) {
            UserBrandAssignment::create([
                'user_id' => $user->id,
                'brand' => $brand,
                'assigned_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message' => 'Brand assignments updated.',
            'brands' => $brands,
        ]);
    }

    public function customerAssignments(User $user): JsonResponse
    {
        $this->ensureCanAssign(request()->user(), false);

        return response()->json(
            $user->customerAssignments()
                ->orderBy('customer_acumatica_id')
                ->get(['id', 'customer_acumatica_id', 'assignment_type', 'notes', 'source', 'source_batch_id', 'created_at']),
        );
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        $query = AcumaticaCustomer::query()
            ->select(['id', 'acumatica_id', 'name', 'customer_class', 'status'])
            ->orderBy('name')
            ->limit(25);

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                    ->orWhere('acumatica_id', 'like', "%{$q}%");
            });
        }

        return response()->json($query->get());
    }

    public function syncCustomerAssignments(User $user, Request $request, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign($request->user(), false);

        $validated = $request->validate([
            'customer_acumatica_ids' => ['required', 'array'],
            'customer_acumatica_ids.*' => ['string', 'max:50'],
        ]);

        $service->syncAssignments(
            $user,
            $validated['customer_acumatica_ids'],
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Customer assignments updated.',
            'assignments' => $user->customerAssignments()
                ->orderBy('customer_acumatica_id')
                ->get(['id', 'customer_acumatica_id', 'assignment_type', 'notes']),
        ]);
    }

    public function backfillCustomers(User $user, Request $request, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign($request->user(), false);

        $result = $service->backfillFromSalesOrders($user, $request->user()?->id);

        return response()->json([
            'message' => "Backfilled {$result['added']} new customer assignment(s).",
            'result' => $result,
            'assignments' => $user->customerAssignments()
                ->orderBy('customer_acumatica_id')
                ->get(['id', 'customer_acumatica_id', 'assignment_type', 'notes']),
        ]);
    }

    public function assignmentSources(CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign(request()->user(), false);

        return response()->json($service->sourceStatus());
    }

    public function previewSalesOrderMatch(User $user, Request $request, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign($request->user(), false);

        $batch = $service->previewFromSalesOrders($user, $request->user()?->id);

        return response()->json($service->presentBatch($batch), 201);
    }

    public function previewCustomerEndpointMatch(User $user, Request $request, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign($request->user(), false);

        $validated = $request->validate([
            'rep_field' => ['nullable', 'string', 'max:100'],
        ]);

        $batch = $service->previewFromCustomerEndpoint(
            $user,
            $request->user()?->id,
            $validated['rep_field'] ?? null,
        );

        return response()->json($service->presentBatch($batch), 201);
    }

    public function uploadCustomerAssignments(Request $request, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign($request->user(), true);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $batch = $service->previewUpload($validated['file'], $request->user()?->id);

        return response()->json($service->presentBatch($batch), 201);
    }

    public function showAssignmentBatch(CustomerAssignmentBatch $batch, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign(request()->user(), false);

        return response()->json($service->presentBatch($batch));
    }

    public function applyAssignmentBatch(CustomerAssignmentBatch $batch, Request $request, CustomerAssignmentService $service): JsonResponse
    {
        $this->ensureCanAssign($request->user(), true);

        $batch = $service->applyBatch($batch, $request->user()?->id);

        return response()->json($service->presentBatch($batch));
    }

    private function ensureCanAssign(?User $actor, bool $manageAll): void
    {
        if (! $actor) {
            abort(403, 'Forbidden.');
        }

        if ($manageAll && ! $actor->hasPermission('customers.assign.manage_all') && ! $actor->hasPermission('customers.assign.manage')) {
            abort(403, 'Forbidden.');
        }

        if (! $manageAll && ! $actor->hasPermission('customers.assign.view') && ! $actor->hasPermission('customers.assign.manage')) {
            abort(403, 'Forbidden.');
        }
    }
}
