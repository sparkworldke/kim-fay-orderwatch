<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRepCodeHistory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KpRepCodeAlignment202608Seeder extends Seeder
{
    private const REP_CODES = [
        'P317' => 'YVON',
        'P460' => 'C967',
        'P483' => 'C1262',
    ];

    public function run(): void
    {
        $updated = 0;
        $missing = [];

        DB::transaction(function () use (&$updated, &$missing): void {
            foreach (self::REP_CODES as $employeeNumber => $repCode) {
                $user = User::query()->where('employee_number', $employeeNumber)->first();
                if (! $user) {
                    $missing[] = $employeeNumber;

                    continue;
                }

                if ($user->rep_code !== $repCode) {
                    $user->forceFill(['rep_code' => $repCode])->save();
                    $updated++;
                }

                UserRepCodeHistory::query()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'rep_code' => $repCode,
                        'change_reason' => 'KP customer portfolio alignment 2026-08',
                    ],
                    [
                        'changed_by_name' => self::class,
                        'changed_at' => now(),
                    ],
                );
            }
        });

        $this->command?->info("KP rep-code alignment: {$updated} user profiles updated.");
        if ($missing !== []) {
            $this->command?->warn('KP rep-code users not found by employee_number: '.implode(', ', $missing));
        }
    }
}
