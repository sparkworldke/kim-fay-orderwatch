<?php

namespace App\Services\Production;

use App\Models\User;

/**
 * Who may create/update production SKU plans and bulk-upload MSI / safety / buffer stock.
 *
 * Allowed:
 * - Administrator / super admin (via hasPermission)
 * - users with production.planning.manage
 * - COO / C-suite / executive org levels
 * - Production department HOD / executive
 * - Stores department HOD / executive (store managers)
 */
class ProductionPlanningAccess
{
    private const MANAGE_PERMISSION = 'production.planning.manage';

    /** Department slugs that may manage planning when the user is HOD/executive. */
    private const MANAGER_DEPARTMENTS = ['production', 'stores'];

    /** Department roles that count as managers for those departments. */
    private const MANAGER_ROLES = ['hod', 'executive', 'manager'];

    public function canManage(?User $user): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        if ($user->hasPermission(self::MANAGE_PERMISSION)) {
            return true;
        }

        if (in_array((string) $user->org_level, ['c_suite', 'executive'], true)) {
            return true;
        }

        return $this->isDepartmentManager($user);
    }

    private function isDepartmentManager(User $user): bool
    {
        $role = strtolower(trim((string) $user->department_role));
        if (! in_array($role, self::MANAGER_ROLES, true)) {
            // Also allow membership_role on department_user pivot.
            $viaPivot = $user->departments()
                ->whereIn('slug', self::MANAGER_DEPARTMENTS)
                ->wherePivotIn('membership_role', self::MANAGER_ROLES)
                ->exists();

            return $viaPivot;
        }

        $primarySlug = $user->primaryDepartmentSlug();
        if ($primarySlug !== null && in_array($primarySlug, self::MANAGER_DEPARTMENTS, true)) {
            return true;
        }

        return $user->departments()
            ->whereIn('slug', self::MANAGER_DEPARTMENTS)
            ->exists();
    }
}
