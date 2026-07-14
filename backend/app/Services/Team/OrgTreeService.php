<?php

namespace App\Services\Team;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class OrgTreeService
{
    /** @return list<int> */
    public function descendantIds(int $userId, bool $includeSelf = false): array
    {
        $ids = $includeSelf ? [$userId] : [];
        $queue = [$userId];

        while ($queue !== []) {
            $current = array_shift($queue);
            $children = User::query()
                ->where('reports_to_user_id', $current)
                ->pluck('id')
                ->all();

            foreach ($children as $childId) {
                if (in_array($childId, $ids, true)) {
                    continue;
                }
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * True when assigning $userId → report to $reportsToUserId would form a loop.
     *
     * A cycle exists if the proposed manager is the user themself, or the manager
     * already sits in the user's reporting subtree (manager reports up into the user).
     * Being a current reportee of the manager is fine (same manager / move within tree).
     */
    public function wouldCreateCycle(int $userId, ?int $reportsToUserId): bool
    {
        if ($reportsToUserId === null) {
            return false;
        }

        if ($reportsToUserId === $userId) {
            return true;
        }

        // Cycle only if the proposed manager is already under this user.
        return in_array($reportsToUserId, $this->descendantIds($userId, false), true);
    }

    /**
     * Guardrail for dynamic reports-to: any user may report to any other user,
     * subject only to existence, active status, not-self, and no hierarchy cycle.
     * No role / org-level / department restrictions.
     *
     * @throws InvalidArgumentException
     */
    public function assertValidReportsTo(int $userId, ?int $reportsToUserId): void
    {
        if ($reportsToUserId === null) {
            return;
        }

        if ($reportsToUserId === $userId) {
            throw new InvalidArgumentException('A user cannot report to themselves.');
        }

        $manager = User::query()->find($reportsToUserId);
        if ($manager === null) {
            throw new InvalidArgumentException('Selected manager does not exist.');
        }

        // Inactive managers are allowed — staff may still report into suspended accounts.

        if ($this->wouldCreateCycle($userId, $reportsToUserId)) {
            throw new InvalidArgumentException(
                'Reports-to assignment would create a cycle. Pick a manager who is not under this user in the org tree.',
            );
        }
    }

    /**
     * Eligible managers for a user: any account (active or inactive) except the user
     * and their reportee subtree. Fully dynamic — no fixed HOD/C-suite shortlist.
     *
     * @return Builder<User>
     */
    public function eligibleManagersQuery(?int $forUserId = null): Builder
    {
        $query = User::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('email');

        if ($forUserId !== null) {
            $blocked = array_values(array_unique(array_merge(
                [$forUserId],
                $this->descendantIds($forUserId, false),
            )));
            $query->whereNotIn('id', $blocked);
        }

        return $query;
    }

    /**
     * @return Collection<int, User>
     */
    public function eligibleManagers(?int $forUserId = null): Collection
    {
        return $this->eligibleManagersQuery($forUserId)
            ->get(['id', 'name', 'email', 'role', 'org_level', 'is_active']);
    }

    /** @return Collection<int, User> */
    public function directReportees(int $userId): Collection
    {
        return User::query()
            ->where('reports_to_user_id', $userId)
            ->orderBy('name')
            ->get();
    }
}