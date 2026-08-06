<?php

namespace App\Services\Team;

use App\Models\User;

/** Central authority for broad business visibility and user administration. */
class AccessTierService
{
    public function canManageUsers(?User $user): bool
    {
        return (bool) ($user?->is_active && $user->is_super_admin);
    }

    public function hasUnrestrictedBusinessAccess(?User $user): bool
    {
        if (! $user?->is_active) {
            return false;
        }

        // Explicit privileged identities always win over consultant duties.
        // Keep this before exclusive-consultant evaluation so impersonation and
        // dual-role accounts can never be accidentally portfolio constrained.
        if ($user->is_super_admin || $user->role === 'Administrator' || $user->role === 'Executive') {
            return true;
        }

        if (in_array((string) $user->org_level, ['executive', 'c_suite'], true)
            || $user->department_role === 'executive') {
            return true;
        }

        // Outlet/portfolio restrictions are reserved for users whose complete
        // effective role set is Sales Consultant. A person may also service a
        // portfolio without losing the broader visibility granted by another
        // role (for example commercialtechlead is both an administrator and a
        // sales consultant).
        if (! $this->isExclusivelySalesConsultant($user)) {
            return true;
        }

        return in_array($user->primaryDepartmentSlug(), ['customer_service', 'production'], true);
    }

    public function isExclusivelySalesConsultant(?User $user): bool
    {
        if (! $user?->is_active) {
            return false;
        }

        $roles = $user->roles()
            ->pluck('name')
            ->push($user->role)
            ->filter(fn (mixed $role) => trim((string) $role) !== '')
            ->map(fn (mixed $role) => strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', trim((string) $role))))
            ->unique()
            ->values();

        return $roles->isNotEmpty()
            && $roles->every(fn (string $role) => $role === 'sales_consultant');
    }

    public function canAccessKpOperations(?User $user): bool
    {
        return $this->hasUnrestrictedBusinessAccess($user)
            || $this->hasCrossChannelBrandAccess($user);
    }

    /** Partner Brands sees every customer channel, with product lines brand-scoped separately. */
    public function hasCrossChannelBrandAccess(?User $user): bool
    {
        return (bool) ($user?->is_active && $user->primaryDepartmentSlug() === 'partner_brands');
    }
}
