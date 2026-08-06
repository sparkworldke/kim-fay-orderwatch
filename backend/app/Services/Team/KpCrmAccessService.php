<?php

namespace App\Services\Team;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class KpCrmAccessService
{
    public function __construct(private readonly AccessTierService $accessTier) {}

    /** @return array{allowed: bool, basis: list<string>, reason: ?string} */
    public function resolve(User $user): array
    {
        if (! $user->is_active) {
            return ['allowed' => false, 'basis' => [], 'reason' => 'inactive_user'];
        }

        if ($this->accessTier->canAccessKpOperations($user)) {
            $basis = $this->accessTier->hasUnrestrictedBusinessAccess($user)
                ? 'unrestricted_business_access'
                : 'partner_brands_cross_channel';
            return ['allowed' => true, 'basis' => [$basis], 'reason' => null];
        }

        $basis = [];
        if ($user->primaryDepartmentSlug() === 'kp' || $user->departments()->where('departments.slug', 'kp')->exists()) {
            $basis[] = 'kp_department';
        }

        $approved = DB::table('kp_crm_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('access_basis')
            ->all();
        $basis = array_values(array_unique([...$basis, ...$approved]));

        $hasPermission = $user->hasPermission('kp.crm.access')
            || $user->hasPermission('kp.accounts.view')
            || $user->hasPermission('kp.fol.view');

        return [
            'allowed' => $hasPermission && $basis !== [],
            'basis' => $basis,
            'reason' => ! $hasPermission ? 'missing_kp_crm_permission' : ($basis === [] ? 'outside_approved_cohort' : null),
        ];
    }

    public function canAccess(User $user): bool
    {
        return $this->resolve($user)['allowed'];
    }
}
