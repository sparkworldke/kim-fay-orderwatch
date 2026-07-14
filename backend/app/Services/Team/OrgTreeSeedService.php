<?php

namespace App\Services\Team;

use App\Models\User;

class OrgTreeSeedService
{
    /** @return array{linked: int, missing: list<string>} */
    public function seed(bool $dryRun = false): array
    {
        $nodes = config('org_tree.nodes', []);
        $emailToId = User::query()->pluck('id', 'email')->map(fn ($id, $email) => [
            'id' => $id,
            'email' => strtolower($email),
        ]);

        $byEmail = [];
        foreach (User::query()->get(['id', 'email']) as $user) {
            $byEmail[strtolower($user->email)] = $user->id;
        }

        $linked = 0;
        $missing = [];

        foreach ($nodes as $node) {
            $email = strtolower(trim((string) ($node['email'] ?? '')));
            if ($email === '' || ! isset($byEmail[$email])) {
                $missing[] = $email;

                continue;
            }

            $reportsToEmail = $node['reports_to'] ?? null;
            $reportsToId = null;
            if ($reportsToEmail !== null) {
                $reportsToEmail = strtolower(trim($reportsToEmail));
                $reportsToId = $byEmail[$reportsToEmail] ?? null;
                if ($reportsToId === null) {
                    $missing[] = "{$email} → {$reportsToEmail}";

                    continue;
                }
            }

            if (! $dryRun) {
                User::query()->whereKey($byEmail[$email])->update([
                    'reports_to_user_id' => $reportsToId,
                ]);
            }
            $linked++;
        }

        $apex = strtolower(trim((string) config('org_tree.apex_email', '')));
        if ($apex !== '' && isset($byEmail[$apex]) && ! $dryRun) {
            User::query()->whereKey($byEmail[$apex])->update(['reports_to_user_id' => null]);
        }

        return ['linked' => $linked, 'missing' => $missing];
    }
}