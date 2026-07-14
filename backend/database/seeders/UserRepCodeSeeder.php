<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRepCodeSeeder extends Seeder
{
    /**
     * Kim-Fay sales reps with their rep_code and employee_number.
     * Each entry also seeds the user_acumatica_rep_mappings table.
     */
    private const REPS = [
        ['id' => 5,  'rep_code' => 'C1048', 'employee_number' => null],
        ['id' => 6,  'rep_code' => 'C1144', 'employee_number' => 'C1144'],
        ['id' => 7,  'rep_code' => 'C1262', 'employee_number' => 'P483'],
        ['id' => 8,  'rep_code' => 'C599',  'employee_number' => 'P370'],
        ['id' => 52, 'rep_code' => 'C835',  'employee_number' => null],
        ['id' => 9,  'rep_code' => 'C939',  'employee_number' => 'C939'],
        ['id' => 10, 'rep_code' => 'C967',  'employee_number' => 'P460'],
        ['id' => 11, 'rep_code' => 'JUM',   'employee_number' => 'P391'],
        ['id' => 12, 'rep_code' => 'P013',  'employee_number' => 'P013'],
        ['id' => 13, 'rep_code' => 'P014',  'employee_number' => 'P014'],
        ['id' => 14, 'rep_code' => 'P022',  'employee_number' => 'P022'],
        ['id' => 15, 'rep_code' => 'P025',  'employee_number' => 'P025'],
        ['id' => 16, 'rep_code' => 'P033',  'employee_number' => 'P033'],
        ['id' => 17, 'rep_code' => 'P076',  'employee_number' => 'P076'],
        ['id' => 18, 'rep_code' => 'P084',  'employee_number' => 'P084'],
        ['id' => 19, 'rep_code' => 'P096',  'employee_number' => 'P096'],
        ['id' => 20, 'rep_code' => 'P104',  'employee_number' => 'P104'],
        ['id' => 21, 'rep_code' => 'P105',  'employee_number' => null],
        ['id' => 22, 'rep_code' => 'P120',  'employee_number' => 'P120'],
        ['id' => 23, 'rep_code' => 'P149',  'employee_number' => 'P149'],
        ['id' => 24, 'rep_code' => 'P183',  'employee_number' => null],
        ['id' => 25, 'rep_code' => 'P193',  'employee_number' => 'P193'],
        ['id' => 26, 'rep_code' => 'P201',  'employee_number' => 'P201'],
        ['id' => 27, 'rep_code' => 'P230',  'employee_number' => 'P230'],
        ['id' => 28, 'rep_code' => 'P245',  'employee_number' => 'P245'],
        ['id' => 29, 'rep_code' => 'P267',  'employee_number' => null],
        ['id' => 30, 'rep_code' => 'P272',  'employee_number' => 'P272'],
        ['id' => 31, 'rep_code' => 'P293',  'employee_number' => 'P293'],
        ['id' => 32, 'rep_code' => 'P321',  'employee_number' => 'P321'],
        ['id' => 33, 'rep_code' => 'P345',  'employee_number' => 'P345'],
        ['id' => 34, 'rep_code' => 'P380',  'employee_number' => 'P380'],
        ['id' => 35, 'rep_code' => 'P385',  'employee_number' => 'P385'],
        ['id' => 36, 'rep_code' => 'P395',  'employee_number' => 'P395'],
        ['id' => 37, 'rep_code' => 'P400',  'employee_number' => 'P400'],
        ['id' => 38, 'rep_code' => 'P413',  'employee_number' => 'P413'],
        ['id' => 39, 'rep_code' => 'P415',  'employee_number' => 'P415'],
        ['id' => 40, 'rep_code' => 'P427',  'employee_number' => 'P427'],
        ['id' => 41, 'rep_code' => 'P438',  'employee_number' => 'P438'],
        ['id' => 42, 'rep_code' => 'P443',  'employee_number' => 'P443'],
        ['id' => 43, 'rep_code' => 'P455',  'employee_number' => 'P455'],
        ['id' => 44, 'rep_code' => 'P461',  'employee_number' => 'P461'],
        ['id' => 45, 'rep_code' => 'P481',  'employee_number' => 'P481'],
        ['id' => 46, 'rep_code' => 'P487',  'employee_number' => 'P487'],
        ['id' => 47, 'rep_code' => 'P489',  'employee_number' => 'P489'],
        ['id' => 4,  'rep_code' => 'P504',  'employee_number' => 'P504'],
        ['id' => 3,  'rep_code' => 'P505',  'employee_number' => 'P505'],
        ['id' => 53, 'rep_code' => 'YVON',  'employee_number' => 'P317'],
    ];

    public function run(): void
    {
        $now = now();

        $applied = 0;
        $skipped = [];

        foreach (self::REPS as $rep) {
            $existing = DB::table('users')->where('id', $rep['id'])->first();

            if (! $existing) {
                // The user row doesn't exist (e.g. production has different IDs).
                // Skip to avoid a foreign-key violation on user_acumatica_rep_mappings.
                $skipped[] = $rep['rep_code'];

                continue;
            }

            // 1) Upsert rep_code on the users table (always set).
            //    For employee_number, only set it when we have a value AND
            //    the current DB value is NULL (don't clobber existing data).
            $updates = [];

            if ($existing->rep_code !== $rep['rep_code']) {
                $updates['rep_code'] = $rep['rep_code'];
            }

            if (
                $rep['employee_number'] !== null
                && $existing->employee_number !== $rep['employee_number']
            ) {
                $updates['employee_number'] = $rep['employee_number'];
            }

            if ($updates !== []) {
                $updates['updated_at'] = $now;
                DB::table('users')->where('id', $rep['id'])->update($updates);
            }

            // 2) Upsert into user_acumatica_rep_mappings.
            //    One primary mapping per user ↔ rep_code pair.
            DB::table('user_acumatica_rep_mappings')->updateOrInsert(
                [
                    'user_id' => $rep['id'],
                    'acumatica_rep_code' => $rep['rep_code'],
                ],
                [
                    'is_primary' => true,
                    'acumatica_consultant_id' => null,
                    'updated_at' => $now,
                ],
            );

            $applied++;
        }

        $this->command->info(
            sprintf(
                'UserRepCodeSeeder: %d reps upserted (users + rep mappings).',
                $applied,
            ),
        );

        if ($skipped !== []) {
            $this->command->warn(
                sprintf(
                    'Skipped %d reps whose user IDs do not exist in this database: %s',
                    count($skipped),
                    implode(', ', $skipped),
                ),
            );
        }
    }
}
