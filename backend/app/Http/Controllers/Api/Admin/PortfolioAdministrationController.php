<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamMigrationBatch;
use App\Models\User;
use App\Services\Team\CustomerAttributionService;
use App\Services\Team\KpCrmAccessService;
use App\Services\Team\TeamMigrationService;
use App\Services\Team\SalesChannelClassificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortfolioAdministrationController extends Controller
{
    public function userPortfolio(User $user, CustomerAttributionService $service): JsonResponse
    {
        $direct = $service->directCustomerIds($user->id);
        $visible = $service->visibleCustomerIds($user->id);

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'employee_number', 'rep_code', 'department_id', 'reports_to_user_id']),
            'direct_customer_ids' => $direct,
            'inherited_customer_ids' => array_values(array_diff($visible, $direct)),
            'direct_count' => count($direct),
            'inherited_count' => count(array_diff($visible, $direct)),
        ]);
    }

    public function kpAccess(Request $request, KpCrmAccessService $service): JsonResponse
    {
        $users = User::query()->where('is_active', true)->orderBy('name')->get();

        return response()->json($users->map(fn (User $user) => [
            'user' => $user->only(['id', 'name', 'email', 'department_id']),
            ...$service->resolve($user),
        ])->filter(fn ($row) => $row['basis'] !== [] || $row['allowed'])->values());
    }

    public function previewMigration(Request $request, TeamMigrationService $service): JsonResponse
    {
        $this->ensureHierarchyAdmin($request->user());
        $data = $request->validate([
            'operation' => ['required', Rule::in([TeamMigrationBatch::OPERATION_REPLACE_HOD, TeamMigrationBatch::OPERATION_TRANSFER_MEMBERS])],
            'source_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'destination_department_id' => ['required', 'integer', 'exists:departments,id'],
            'new_hod_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'include_subtrees' => ['array'],
            'effective_date' => ['required', 'date'],
            'change_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return response()->json($service->preview(
            $request->user(),
            $data['operation'],
            $data['source_department_id'] ?? null,
            $data['destination_department_id'],
            $data['new_hod_user_id'] ?? null,
            $data['member_ids'] ?? [],
            $data['include_subtrees'] ?? [],
            $data['effective_date'],
            $data['change_reason'],
        ), 201);
    }

    public function applyMigration(Request $request, TeamMigrationBatch $batch, TeamMigrationService $service): JsonResponse
    {
        $this->ensureHierarchyAdmin($request->user());
        return response()->json($service->apply($request->user(), $batch));
    }

    public function channelClassification(): JsonResponse
    {
        return response()->json([
            'category_rules' => DB::table('sales_channel_category_rules')->orderBy('priority')->orderBy('customer_category')->get(),
            'customer_overrides' => DB::table('customer_sales_channel_overrides')->orderByDesc('updated_at')->limit(500)->get(),
            'channels' => DB::table('sales_channels')->where('is_active', true)->orderBy('sort_order')->get(['code', 'name']),
        ]);
    }

    public function storeCategoryRule(Request $request, SalesChannelClassificationService $service): JsonResponse
    {
        $data = $request->validate([
            'customer_category' => ['required', 'string', 'max:50'],
            'sales_channel_code' => ['required', 'string', 'exists:sales_channels,code'],
            'priority' => ['nullable', 'integer', 'between:1,1000'],
            'change_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $category = strtoupper(trim($data['customer_category']));
        $channel = strtoupper($data['sales_channel_code']);
        abort_if(
            DB::table('sales_channel_category_rules')
                ->where('customer_category', $category)
                ->where('sales_channel_code', '!=', $channel)
                ->where('is_active', true)
                ->exists(),
            422,
            'A category can map to only one canonical primary channel. Use a customer-ID override for exceptions.',
        );

        DB::table('sales_channel_category_rules')->updateOrInsert(
            ['customer_category' => $category, 'sales_channel_code' => $channel],
            [
                'priority' => $data['priority'] ?? 100,
                'is_active' => true,
                'created_by' => $request->user()->id,
                'change_reason' => $data['change_reason'],
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['statistics' => $service->classifyAll()]);
    }

    public function storeCustomerChannelOverride(Request $request, SalesChannelClassificationService $service): JsonResponse
    {
        $data = $request->validate([
            'customer_acumatica_id' => ['required', 'string', 'max:50'],
            'sales_channel_code' => ['required', 'string', 'exists:sales_channels,code'],
            'change_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $service->setCustomerOverride(
            strtoupper(trim($data['customer_acumatica_id'])),
            strtoupper($data['sales_channel_code']),
            $request->user()->id,
            $data['change_reason'],
        );

        return response()->json(['message' => 'Customer channel override saved.']);
    }

    private function ensureHierarchyAdmin(User $user): void
    {
        abort_unless(
            $user->is_super_admin || $user->role === 'Administrator' || $user->hasPermission('team.manage_hierarchy'),
            403,
            'You do not have permission to manage team hierarchy.',
        );
    }
}
