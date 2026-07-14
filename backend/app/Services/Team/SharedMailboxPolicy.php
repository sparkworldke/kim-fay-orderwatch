<?php

namespace App\Services\Team;

use App\Models\User;

class SharedMailboxPolicy
{
    public function isSharedMailboxEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        $list = array_map('strtolower', config('departments.shared_mailbox_emails', []));

        if (in_array($email, $list, true)) {
            return true;
        }

        $local = strstr($email, '@', true) ?: $email;
        foreach (config('departments.shared_mailbox_local_parts', []) as $part) {
            if (str_starts_with($local, strtolower($part))) {
                return true;
            }
        }

        return str_contains($local, 'intern');
    }

    public function applyToUser(User $user): void
    {
        if (! $this->isSharedMailboxEmail($user->email)) {
            return;
        }

        $user->forceFill([
            'is_shared_mailbox' => true,
            'org_level' => 'gap',
            'data_scope_mode' => 'deny_all',
            'is_active' => false,
        ])->save();
    }

    /** @return array{updated: int} */
    public function applyToAllUsers(): array
    {
        $updated = 0;

        User::query()->each(function (User $user) use (&$updated) {
            if ($this->isSharedMailboxEmail($user->email) && ! $user->is_shared_mailbox) {
                $this->applyToUser($user);
                $updated++;
            }
        });

        return ['updated' => $updated];
    }
}