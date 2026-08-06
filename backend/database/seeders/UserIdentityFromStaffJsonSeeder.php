<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRepCodeHistory;
use App\Services\Cache\DomainCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Correct users.employee_number (and rep_code when known) from JSON built from:
 * - excels/Active staff July 2026- HQ.xlsx
 * - excels/Kimfay Employees - HODs.csv (name match)
 * - optional KP customer Sales Rep → Rep Code map
 *
 * Match users by normalized name — never by hard-coded user id.
 *
 * Rebuild JSON:
 *   python backend/scripts/build_user_identity_json.py
 *
 * Run:
 *   php artisan db:seed --class=UserIdentityFromStaffJsonSeeder
 */
class UserIdentityFromStaffJsonSeeder extends Seeder
{
    private const IDENTITY_JSON = 'data/user-identity-seed-2026-08.json';

    private const KP_REP_JSON = 'data/kp-sales-rep-codes-2026-08.json';

    public function run(): void
    {
        $identityPath = __DIR__.'/'.self::IDENTITY_JSON;
        if (! is_file($identityPath)) {
            throw new RuntimeException(
                'Missing '.self::IDENTITY_JSON.'. Run: python backend/scripts/build_user_identity_json.py'
            );
        }

        /** @var array{users?: list<array<string, mixed>>} $identity */
        $identity = json_decode((string) file_get_contents($identityPath), true, 512, JSON_THROW_ON_ERROR);
        $rows = $identity['users'] ?? [];
        if ($rows === []) {
            throw new RuntimeException('user-identity-seed JSON has no users[].');
        }

        $kpRepByName = $this->loadKpRepByName();

        $updated = 0;
        $repUpdated = 0;
        $matched = 0;
        $unmatched = [];
        $ambiguous = [];

        DB::transaction(function () use (
            $rows,
            $kpRepByName,
            &$updated,
            &$repUpdated,
            &$matched,
            &$unmatched,
            &$ambiguous,
        ): void {
            foreach ($rows as $row) {
                $matchNames = array_values(array_filter(array_map(
                    static fn ($n) => is_string($n) ? trim($n) : '',
                    $row['match_names'] ?? [],
                )));
                if ($matchNames === []) {
                    continue;
                }

                $users = $this->findUsersByNames($matchNames);
                if ($users->count() === 0) {
                    $unmatched[] = $matchNames[0].' ('.$this->str($row['employee_number']).')';

                    continue;
                }
                if ($users->count() > 1) {
                    $ambiguous[] = $matchNames[0].' → '.$users->pluck('id')->implode(',');

                    continue;
                }

                /** @var User $user */
                $user = $users->first();
                $matched++;

                $employeeNumber = $this->str($row['employee_number']);
                $repCode = $this->str($row['rep_code'] ?? null);

                if ($repCode === null) {
                    $repCode = $this->repCodeFromKpMap($matchNames, $kpRepByName)
                        ?? $this->repCodeFromKpMap([$user->name], $kpRepByName);
                }

                $updates = [];
                if ($employeeNumber !== null && strtoupper((string) $user->employee_number) !== $employeeNumber) {
                    $updates['employee_number'] = $employeeNumber;
                }

                $oldRep = $user->rep_code ? strtoupper(trim((string) $user->rep_code)) : null;
                if ($repCode !== null && $oldRep !== $repCode) {
                    $updates['rep_code'] = $repCode;
                }

                foreach (['designation', 'division'] as $field) {
                    $value = $this->nullableString($row[$field] ?? null);
                    if ($value !== null && blank($user->{$field})) {
                        $updates[$field] = $value;
                    }
                }

                if ($updates === []) {
                    if ($repCode !== null) {
                        $this->ensureRepMapping($user->id, $repCode);
                    }

                    continue;
                }

                if (isset($updates['rep_code'])) {
                    UserRepCodeHistory::query()->firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'rep_code' => $updates['rep_code'],
                            'change_reason' => 'User identity JSON seed 2026-08 (Active staff + HODs name match)',
                        ],
                        [
                            'changed_by_name' => self::class,
                            'changed_at' => now(),
                        ],
                    );
                    $repUpdated++;
                }

                $user->forceFill($updates)->save();
                $updated++;

                if (isset($updates['rep_code']) || $repCode !== null) {
                    $this->ensureRepMapping($user->id, $updates['rep_code'] ?? $repCode);
                }
            }

            // Second pass: KP sales rep map may hit users not listed as HOD/staff-only.
            foreach ($kpRepByName as $nameKey => $meta) {
                $users = $this->findUsersByNames($meta['names']);
                if ($users->count() !== 1) {
                    continue;
                }
                $user = $users->first();
                $repCode = $meta['rep_code'];
                $employeeNumber = $meta['employee_number'];
                $updates = [];
                if ($employeeNumber && strtoupper((string) $user->employee_number) !== $employeeNumber) {
                    $updates['employee_number'] = $employeeNumber;
                }
                if ($repCode && strtoupper((string) ($user->rep_code ?? '')) !== $repCode) {
                    $updates['rep_code'] = $repCode;
                }
                if ($updates === []) {
                    $this->ensureRepMapping($user->id, $repCode);

                    continue;
                }
                if (isset($updates['rep_code'])) {
                    UserRepCodeHistory::query()->firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'rep_code' => $updates['rep_code'],
                            'change_reason' => 'KP sales rep code from customers CSV name match 2026-08',
                        ],
                        [
                            'changed_by_name' => self::class,
                            'changed_at' => now(),
                        ],
                    );
                    $repUpdated++;
                }
                $user->forceFill($updates)->save();
                $updated++;
                $this->ensureRepMapping($user->id, $repCode);
            }

            // Wire reports_to from HOD JSON when both sides resolve by employee_number.
            $this->applyReportsTo($rows);
        });

        app(DomainCache::class)->bump(
            DomainCache::CAPABILITIES,
            DomainCache::REFERENCES,
            DomainCache::SALES_PORTFOLIO,
        );

        $this->command?->info(sprintf(
            'UserIdentityFromStaffJsonSeeder: matched %d staff rows, updated %d users (%d rep_code changes).',
            $matched,
            $updated,
            $repUpdated,
        ));

        if ($ambiguous !== []) {
            $this->command?->warn('Ambiguous name matches (skipped): '.implode('; ', array_slice($ambiguous, 0, 15)));
        }
        if ($unmatched !== []) {
            $this->command?->warn(sprintf(
                '%d staff/HOD names had no matching user (first 20): %s',
                count($unmatched),
                implode('; ', array_slice($unmatched, 0, 20)),
            ));
        }
    }

    /**
     * @param  list<string>  $names
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function findUsersByNames(array $names)
    {
        $keys = [];
        foreach ($names as $name) {
            $keys[] = $this->normalizeName($name);
            // Also try without middle names noise later via token match.
        }
        $keys = array_values(array_unique(array_filter($keys)));

        $candidates = User::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'email', 'employee_number', 'rep_code', 'designation', 'division']);

        $hits = $candidates->filter(function (User $user) use ($keys, $names) {
            $userKey = $this->normalizeName((string) $user->name);
            if (in_array($userKey, $keys, true)) {
                return true;
            }
            $userTokens = $this->tokens((string) $user->name);
            foreach ($names as $name) {
                $nameTokens = $this->tokens($name);
                if (count(array_intersect($userTokens, $nameTokens)) >= 2) {
                    return true;
                }
            }

            return false;
        })->values();

        // Prefer exact normalized match when multiple token hits.
        $exact = $hits->filter(fn (User $u) => in_array($this->normalizeName((string) $u->name), $keys, true));

        return $exact->isNotEmpty() ? $exact->values() : $hits;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function applyReportsTo(array $rows): void
    {
        $byEmployee = User::query()
            ->whereNotNull('employee_number')
            ->get()
            ->keyBy(fn (User $u) => strtoupper(trim((string) $u->employee_number)));

        foreach ($rows as $row) {
            $emp = $this->str($row['employee_number'] ?? null);
            $managerEmp = $this->str($row['reports_to_employee_number'] ?? null);
            if ($emp === null || $managerEmp === null) {
                continue;
            }
            $user = $byEmployee->get($emp);
            $manager = $byEmployee->get($managerEmp);
            if (! $user || ! $manager || (int) $user->reports_to_user_id === (int) $manager->id) {
                continue;
            }
            $user->forceFill(['reports_to_user_id' => $manager->id])->save();
        }
    }

    private function ensureRepMapping(int $userId, ?string $repCode): void
    {
        if ($repCode === null || $repCode === '') {
            return;
        }

        DB::table('user_acumatica_rep_mappings')->updateOrInsert(
            [
                'user_id' => $userId,
                'acumatica_rep_code' => $repCode,
            ],
            [
                'is_primary' => true,
                'acumatica_consultant_id' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, array{rep_code: string, employee_number: ?string, names: list<string>}>
     */
    private function loadKpRepByName(): array
    {
        $path = __DIR__.'/'.self::KP_REP_JSON;
        if (! is_file($path)) {
            return [];
        }

        /** @var array{reps?: list<array<string, mixed>>, aliases?: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $map = [];

        foreach (array_merge($data['aliases'] ?? [], $data['reps'] ?? []) as $row) {
            $repCode = $this->str($row['rep_code'] ?? null);
            if ($repCode === null) {
                continue;
            }
            $names = array_values(array_filter(array_map(
                static fn ($n) => is_string($n) ? trim($n) : '',
                $row['match_names'] ?? [],
            )));
            $employeeNumber = $this->str($row['employee_number'] ?? null);
            foreach ($names as $name) {
                $map[$this->normalizeName($name)] = [
                    'rep_code' => $repCode,
                    'employee_number' => $employeeNumber,
                    'names' => $names,
                ];
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, array{rep_code: string, employee_number: ?string, names: list<string>}>  $map
     */
    private function repCodeFromKpMap(array $names, array $map): ?string
    {
        foreach ($names as $name) {
            $key = $this->normalizeName($name);
            if (isset($map[$key])) {
                return $map[$key]['rep_code'];
            }
            $tokens = $this->tokens($name);
            foreach ($map as $meta) {
                foreach ($meta['names'] as $mapName) {
                    if (count(array_intersect($tokens, $this->tokens($mapName))) >= 2) {
                        return $meta['rep_code'];
                    }
                }
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        $name = Str::lower(trim($name));
        $name = preg_replace("/[^a-z0-9\s']/", ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    /** @return list<string> */
    private function tokens(string $name): array
    {
        $stop = ['of', 'and', 'the', 'de', 'van'];
        $parts = preg_split('/\s+/', $this->normalizeName($name)) ?: [];

        return array_values(array_filter(
            $parts,
            static fn (string $t) => $t !== '' && ! in_array($t, $stop, true) && strlen($t) > 1,
        ));
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
