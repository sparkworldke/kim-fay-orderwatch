<?php

namespace App\Services\Team;

use App\Models\Department;
use App\Models\Permission;
use App\Models\TradingGroup;
use App\Models\User;
use App\Services\Production\ProductionPlanningAccess;
use Illuminate\Support\Facades\DB;

class UserCapabilitiesService
{
    /** @return array<string, mixed> */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return [
                'permissions' => [],
                'menus' => [],
                'mask_revenue' => true,
                'department' => null,
                'has_reportees' => false,
                'sales_intelligence_channels' => [],
                'can_manage_production_planning' => false,
                'can_manage_users' => false,
                'unrestricted_business_access' => false,
                'partner_brand_scope' => ['applies' => false, 'is_hod' => false, 'brands' => [], 'groups' => []],
                'idle_timeout_minutes' => config('departments.idle_timeout_minutes', 60),
            ];
        }

        // Respect the canonical primary pivot membership, falling back to the
        // legacy department_id inside User::primaryDepartment(). This matters
        // for HODs such as Purity whose legacy column may be empty.
        $department = $user->primaryDepartment();

        $hiddenMenus = $this->hiddenMenusForUser($user, $department);
        $allMenus = $this->allMenuSlugs();
        $hasReportees = $user->reportees()->where('is_active', true)->exists();
        $kpCrmAccess = app(KpCrmAccessService::class)->resolve($user);
        $accessTier = app(AccessTierService::class);
        $canManageUsers = $accessTier->canManageUsers($user);
        $unrestrictedBusinessAccess = $accessTier->hasUnrestrictedBusinessAccess($user);

        return [
            'permissions' => $this->permissionSlugs($user),
            'menus' => array_values(array_diff($allMenus, $hiddenMenus)),
            'hidden_menus' => $hiddenMenus,
            'mask_revenue' => $this->shouldMaskRevenue($user),
            'department' => $department ? [
                'id' => $department->id,
                'slug' => $department->slug,
                'name' => $department->name,
                'is_customer_facing' => $department->is_customer_facing,
            ] : null,
            'department_role' => $user->department_role,
            'org_level' => $user->org_level,
            'data_scope_mode' => $user->data_scope_mode,
            'sector_scopes' => $user->relationLoaded('sectorScopes')
                ? $user->sectorScopes->pluck('sector')->values()->all()
                : $user->sectorScopes()->pluck('sector')->all(),
            'is_consultant' => (bool) $user->is_consultant,
            'employee_number' => $user->employee_number,
            'has_reportees' => $hasReportees,
            'sales_intelligence_channels' => $this->salesIntelligenceChannels(
                $user,
                $department,
                $hasReportees,
            ),
            'default_sales_channel' => $this->defaultSalesChannel($department),
            'kp_crm_access' => $kpCrmAccess,
            'can_manage_users' => $canManageUsers,
            'unrestricted_business_access' => $unrestrictedBusinessAccess,
            'executive_view' => $this->canViewExecutive($user),
            'can_manage_production_planning' => app(ProductionPlanningAccess::class)->canManage($user),
            'partner_brand_scope' => $this->partnerBrandScope($user),
            'idle_timeout_minutes' => config('departments.idle_timeout_minutes', 60),
        ];
    }

    private function defaultSalesChannel(?Department $department): ?string
    {
        return match ($department?->slug) {
            'kp' => 'KP',
            'mt_consumer_sales' => 'MT',
            // GT has no live channel-tagged customer data yet — default to MT
            // until General Trade portfolio data exists. Flip to 'GT' then.
            'gt' => 'MT',
            default => null,
        };
    }

    private function canViewExecutive(User $user): bool
    {
        return $user->is_super_admin
            || in_array(strtolower((string) $user->org_level), ['executive', 'c_suite'], true)
            || strtolower((string) $user->department_role) === 'hod'
            || in_array(strtolower(trim((string) $user->email)), [
                'commercialtechlead@kimfay.com', 'rbains@kimfay.com', 'hbains@kimfay.com',
                'djumani@kimfay.com', 'salesstrategy@kimfay.com',
            ], true);
    }

    /** @return array{applies: bool, is_hod: bool, brands: list<string>, groups: list<array{id: int, name: string}>} */
    private function partnerBrandScope(User $user): array
    {
        if (! $user->isPartnerBrandsUser()) {
            return ['applies' => false, 'is_hod' => false, 'brands' => [], 'groups' => []];
        }

        $isHod = $user->department_role === 'hod';
        $brands = app(BrandAssignmentScope::class)->allowedBrands($user) ?? [];
        $groups = TradingGroup::query()
            ->where('is_active', true)
            ->whereHas('partnerBrands', fn ($query) => $query->whereIn('name', $brands))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($group) => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->all();

        return ['applies' => true, 'is_hod' => $isHod, 'brands' => $brands, 'groups' => $groups];
    }

    /** @return list<string> */
    private function permissionSlugs(User $user): array
    {
        if ($user->is_super_admin || $user->role === 'Administrator') {
            return Permission::query()->pluck('name')->all();
        }

        return Permission::query()
            ->whereHas('roles.userRoles', fn ($q) => $q->where('user_id', $user->id))
            ->pluck('name')
            ->all();
    }

    /** @return list<string> */
    private function hiddenMenusForUser(User $user, ?Department $department): array
    {
        $hidden = [];

        if ($department !== null) {
            $hidden = array_merge(
                $hidden,
                config('departments.hidden_menus_by_department.'.$department->slug, []),
            );
        }

        if ($user->role === 'Customer Service Agent') {
            $hidden = array_merge($hidden, ['administration', 'roles', 'team']);
        }

        if ($user->role === 'Sales Consultant' && $department?->slug !== 'partner_brands') {
            $hidden = array_merge($hidden, ['administration', 'roles', 'team', 'mailbox', 'order-match']);
        }

        if (! app(AccessTierService::class)->canManageUsers($user)) {
            $hidden = array_merge($hidden, ['administration', 'roles', 'team', 'adoption']);
        }

        return array_values(array_unique($hidden));
    }

    private function shouldMaskRevenue(User $user): bool
    {
        if ($user->is_super_admin || $user->role === 'Administrator' || $user->role === 'Executive') {
            return false;
        }

        return in_array($user->role, config('departments.mask_revenue_roles', []), true);
    }

    /** @return list<string> */
    private function salesIntelligenceChannels(
        User $user,
        ?Department $department,
        bool $hasReportees,
    ): array {
        $channels = ['portfolio'];
        $accessTier = app(AccessTierService::class);
        $isLeadership = $accessTier->hasUnrestrictedBusinessAccess($user)
            || $accessTier->hasCrossChannelBrandAccess($user);

        if ($hasReportees) {
            $channels[] = 'team';

            $descendantIds = app(OrgTreeService::class)->descendantIds($user->id);
            $teamChannelCodes = DB::table('user_customer_assignments as uca')
                ->join('acumatica_customers as c', 'c.acumatica_id', '=', 'uca.customer_acumatica_id')
                ->whereIn('uca.user_id', $descendantIds ?: [0])
                ->whereNotNull('c.sales_channel_code')
                ->distinct()
                ->pluck('c.sales_channel_code')
                ->map(fn ($channel) => strtolower(trim((string) $channel)))
                ->all();
            $channels = array_merge($channels, $teamChannelCodes);
        }

        if ($isLeadership || $department?->slug === 'mt_consumer_sales') {
            $channels[] = 'mt1';
            $channels[] = 'mt2';
        }

        if ($isLeadership || $department?->slug === 'gt') {
            $channels[] = 'gt';
        }

        if ($isLeadership || $user->hasPermission('dtc.view')) {
            $channels[] = 'dtc_dtb';
        }

        if ($isLeadership) {
            $channels[] = 'ecommerce';
        }

        if (app(KpCrmAccessService::class)->canAccess($user)) {
            $channels[] = 'kp';
        }

        return array_values(array_unique($channels));
    }

    /** @return list<string> */
    private function allMenuSlugs(): array
    {
        return [
            'dashboard',
            'orders',
            'business-optimization',
            'ai-intelligence',
            'customer-feed',
            'credit-notes',
            'inventory',
            'backorders',
            'fill-rate',
            'zones',
            'customers',
            'so-imports',
            'sales-consultants',
            'mailbox',
            'order-match',
            'administration',
            'team',
            'roles',
            'profile',
        ];
    }
}
