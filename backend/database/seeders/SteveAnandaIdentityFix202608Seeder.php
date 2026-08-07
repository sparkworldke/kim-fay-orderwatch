<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SteveAnandaIdentityFix202608Seeder extends Seeder
{
    private const EMAIL = 'salesstrategy@kimfay.com';

    public function run(): void
    {
        $steve = User::query()->where('is_active', true)
            ->where('email', self::EMAIL)
            ->first();

        if (! $steve) {
            throw new RuntimeException('No active user with email '.self::EMAIL.' found; run/link his login first.');
        }

        $changes = [];

        DB::transaction(function () use ($steve, &$changes): void {
            if ($steve->name !== 'Steve Ananda') {
                $changes[] = "name '{$steve->name}' -> 'Steve Ananda'";
                $steve->name = 'Steve Ananda';
            }

            if ($this->isClaimedByAnotherActiveUser($steve, 'rep_code')) {
                $changes[] = "cleared conflicting rep_code '{$steve->rep_code}'";
                $steve->rep_code = null;
            }

            if ($this->isClaimedByAnotherActiveUser($steve, 'employee_number')) {
                $changes[] = "cleared conflicting employee_number '{$steve->employee_number}'";
                $steve->employee_number = null;
            }

            if ($changes !== []) {
                $steve->save();
            }
        });

        $this->command?->info($changes === []
            ? "Steve Ananda's identity ({$steve->email}) already correct; no changes made."
            : "Fixed Steve Ananda's identity ({$steve->email}): ".implode('; ', $changes).'.');
    }

    private function isClaimedByAnotherActiveUser(User $steve, string $column): bool
    {
        $value = $steve->{$column};
        if ($value === null || trim((string) $value) === '') {
            return false;
        }

        return User::query()
            ->where('id', '!=', $steve->id)
            ->where('is_active', true)
            ->whereRaw("UPPER(TRIM({$column})) = ?", [strtoupper(trim((string) $value))])
            ->exists();
    }
}
