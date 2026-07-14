<?php

namespace App\Services\Team;

use App\Models\Department;
use App\Models\User;

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
                'idle_timeout_minutes' => config('departments.idle_timeout_minutes', 60),
            ];
        }

        $department = $user->department_id
            ? Department::query()->find($user->department_id)
            : null;

        $hiddenMenus = $this->hiddenMenusForUser($user, $department);
        $allMenus = $this->allMenuSlugs();

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
            'idle_timeout_minutes' => config('departments.idle_timeout_minutes', 60),
        ];
    }

    /** @return list<string> */
    private function permissionSlugs(User $user): array
    {
        if ($user->is_super_admin || $user->role === 'Administrator') {
            return \App\Models\Permission::query()->pluck('name')->all();
        }

        return \App\Models\Permission::query()
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
                config('departments.hidden_menus_by_department.' . $department->slug, []),
            );
        }

        if ($user->role === 'Customer Service Agent') {
            $hidden = array_merge($hidden, ['administration', 'roles', 'team']);
        }

        if ($user->role === 'Sales Consultant') {
            $hidden = array_merge($hidden, ['administration', 'roles', 'team', 'mailbox', 'order-match']);
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