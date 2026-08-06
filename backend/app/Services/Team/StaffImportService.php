<?php

namespace App\Services\Team;

use App\Models\Department;
use App\Models\OrgChartAudit;
use App\Models\StaffImportGap;
use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserRepCodeHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StaffImportService
{
    public function __construct(
        private readonly UserOrgService $userOrg,
        private readonly SharedMailboxPolicy $sharedMailboxPolicy,
    ) {}

    /** @return array{created: int, updated: int, skipped: int, gaps: int, rep_code_backfilled: int, rep_code_conflicts: int, errors: list<string>} */
    public function import(
        string $path,
        bool $dryRun = false,
        bool $preserveManual = true,
        string $minConfidence = 'high',
    ): array {
        $rows = $this->loadRows($path);
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'gaps' => 0,
            'rep_code_backfilled' => 0,
            'rep_code_conflicts' => 0,
            'errors' => [],
        ];

        if (! $dryRun) {
            StaffImportGap::query()
                ->where('resolution_status', 'open')
                ->whereIn('gap_reason', ['no_staff_match', 'low_confidence', 'rep_code_employee_number_mismatch'])
                ->delete();
        }

        foreach ($rows as $row) {
            $confidence = (string) ($row['match_confidence'] ?? 'low');
            if (! $this->confidenceMeetsMinimum($confidence, $minConfidence)) {
                $stats['gaps']++;
                if (! $dryRun) {
                    $this->recordGap($row, $row['gap_reason'] ?? 'low_confidence');
                }
                continue;
            }

            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $existing = User::query()->where('email', $email)->first();
            if ($preserveManual && $existing !== null && $this->hasManualOrgEdits($existing)) {
                $stats['skipped']++;
                continue;
            }

            $departmentId = $this->resolveDepartmentId(
                (string) ($row['function_slug'] ?? 'gap'),
                (string) ($row['department'] ?? ''),
            );
            $orgLevel = (string) ($row['org_level'] ?? 'sales');
            $departmentRole = $this->mapDepartmentRole($orgLevel);
            $productType = $this->mapProductType((string) ($row['function_slug'] ?? ''));
            $sectorScopes = $this->mapSectorScopes($row['sector_tags'] ?? [], $orgLevel, $departmentId);

            $payload = [
                'name' => trim((string) ($row['display_name'] ?? $row['staff_name'] ?? $email)),
                'employee_number' => $row['employee_number'] ?? null,
                'designation' => $row['designation'] ?? null,
                'division' => $row['division'] ?? null,
                'department_id' => $departmentId,
                'department_ids' => $departmentId ? [$departmentId] : [],
                'department_role' => $departmentRole,
                'org_level' => $orgLevel,
                'product_type_scope' => $productType,
                'data_scope_mode' => $this->userOrg->defaultDataScopeMode($orgLevel),
                'sector_scopes' => $sectorScopes,
            ];

            if ($dryRun) {
                $existing === null ? $stats['created']++ : $stats['updated']++;
                if ($existing !== null) {
                    $this->previewRepCodeReconciliation($existing, $payload, $stats);
                }
                continue;
            }

            try {
                DB::transaction(function () use ($existing, $email, $payload, $row, &$stats) {
                    if ($existing === null) {
                        $user = User::create([
                            'name' => $payload['name'],
                            'email' => $email,
                            'role' => $this->inferAppRole($payload['org_level']),
                            'employee_number' => $payload['employee_number'],
                            'designation' => $payload['designation'],
                            'division' => $payload['division'],
                            'department_id' => $payload['department_id'],
                            'department_role' => $payload['department_role'],
                            'org_level' => $payload['org_level'],
                            'product_type_scope' => $payload['product_type_scope'],
                            'data_scope_mode' => $payload['data_scope_mode'],
                            'is_consultant' => in_array($payload['org_level'], ['sales'], true),
                            'password' => bcrypt(Str::random(40)),
                            'is_active' => false,
                        ]);
                        $this->userOrg->applyOrgConfig($user, $payload);
                        $this->sharedMailboxPolicy->applyToUser($user->fresh());
                        $stats['created']++;
                    } else {
                        $existing->forceFill([
                            'name' => $payload['name'],
                            'employee_number' => $payload['employee_number'] ?? $existing->employee_number,
                            'designation' => $payload['designation'] ?? $existing->designation,
                            'division' => $payload['division'] ?? $existing->division,
                            'department_id' => $payload['department_id'] ?? $existing->department_id,
                            'department_role' => $payload['department_role'],
                            'org_level' => $payload['org_level'],
                            'product_type_scope' => $payload['product_type_scope'],
                            'data_scope_mode' => $payload['data_scope_mode'],
                        ])->save();
                        $this->userOrg->applyOrgConfig($existing, $payload);
                        $this->sharedMailboxPolicy->applyToUser($existing->fresh());
                        $stats['updated']++;
                    }

                    $this->reconcileRepCode($user ?? $existing, $payload, $row, $stats);
                });
            } catch (\Throwable $exception) {
                $stats['errors'][] = "{$email}: {$exception->getMessage()}";
            }
        }

        return $stats;
    }

    /** @return list<array<string, mixed>> */
    public function loadRows(string $path): array
    {
        if (str_ends_with(strtolower($path), '.json')) {
            $data = json_decode((string) file_get_contents($path), true);

            return is_array($data['matches'] ?? null) ? $data['matches'] : [];
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [];
        $rows = [];
        $headerFound = false;

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if (! $headerFound) {
                // Some exports lead with a title/subtitle row before the real header row
                // (e.g. "Matched Staff — Email, Employee Number, ..." then a summary line)
                // — a real header row has more than one populated cell.
                $nonEmpty = count(array_filter($cells, static fn ($v) => $v !== ''));
                if ($nonEmpty <= 1) {
                    continue;
                }
                $headers = array_map('strtolower', $cells);
                $headerFound = true;
                continue;
            }

            if ($cells === [] || ($cells[0] ?? '') === '') {
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                $mapped[$this->normalizeHeader($header)] = $cells[$i] ?? null;
            }
            $rows[] = $this->normalizeImportRow($mapped);
        }

        return $rows;
    }

    public function createUserFromGap(StaffImportGap $gap, ?int $actorId = null): User
    {
        $email = strtolower(trim((string) $gap->email));
        if ($email === '') {
            throw new \InvalidArgumentException('Gap has no email.');
        }

        $payload = is_array($gap->source_payload) ? $gap->source_payload : [];
        $departmentId = $this->resolveDepartmentId(
            (string) ($payload['function_slug'] ?? 'gap'),
            (string) ($payload['department'] ?? ''),
        );
        $orgLevel = (string) ($payload['org_level'] ?? 'gap');

        $user = User::create([
            'name' => trim((string) ($gap->display_name ?? $email)),
            'email' => $email,
            'role' => $this->inferAppRole($orgLevel === 'gap' ? 'sales' : $orgLevel),
            'employee_number' => $gap->employee_number,
            'department_id' => $departmentId,
            'org_level' => $orgLevel,
            'data_scope_mode' => $this->userOrg->defaultDataScopeMode($orgLevel),
            'password' => bcrypt(Str::random(40)),
            'is_active' => false,
        ]);

        $this->userOrg->applyOrgConfig($user, [
            'department_id' => $departmentId,
            'department_ids' => $departmentId ? [$departmentId] : [],
            'org_level' => $orgLevel,
            'sector_scopes' => $this->mapSectorScopes($payload['sector_tags'] ?? [], $orgLevel, $departmentId),
        ]);
        $this->sharedMailboxPolicy->applyToUser($user->fresh());

        $gap->update([
            'resolution_status' => 'linked',
            'resolved_user_id' => $user->id,
        ]);

        return $user->fresh();
    }

    /** @return list<StaffImportGap> */
    public function openGaps(): array
    {
        return StaffImportGap::query()
            ->where('resolution_status', 'open')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->all();
    }

    /** @param  array<string, mixed>  $row */
    private function recordGap(array $row, string $reason): void
    {
        StaffImportGap::create([
            'email' => $row['email'] ?? null,
            'employee_number' => $row['employee_number'] ?? null,
            'display_name' => $row['display_name'] ?? null,
            'gap_reason' => $reason,
            'match_score' => isset($row['match_score']) ? (float) $row['match_score'] : null,
            'source_payload' => $row,
            'resolution_status' => 'open',
        ]);
    }

    private function hasManualOrgEdits(User $user): bool
    {
        return OrgChartAudit::query()
            ->where('user_id', $user->id)
            ->where('change_type', 'org_config_updated')
            ->exists();
    }

    private function confidenceMeetsMinimum(string $confidence, string $minimum): bool
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];

        return ($rank[$confidence] ?? 0) >= ($rank[$minimum] ?? 3);
    }

    private function normalizeHeader(string $header): string
    {
        return str_replace(' ', '_', strtolower(trim($header)));
    }

    /** @param  array<string, mixed>  $mapped */
    private function normalizeImportRow(array $mapped): array
    {
        $sectorRaw = $mapped['sector_tags'] ?? '';
        $sectors = [];
        if (is_string($sectorRaw) && $sectorRaw !== '') {
            $decoded = json_decode($sectorRaw, true);
            $sectors = is_array($decoded) ? $decoded : array_map('trim', explode(',', $sectorRaw));
        }

        return [
            'email' => $mapped['email'] ?? $mapped['email_address'] ?? null,
            'display_name' => $mapped['display_name'] ?? $mapped['employee_name'] ?? null,
            'match_score' => isset($mapped['match_score']) ? (float) $mapped['match_score'] : null,
            'match_confidence' => $mapped['match_confidence']
                ?? (isset($mapped['employee_name'], $mapped['email_address'], $mapped['employee_number']) ? 'high' : 'low'),
            'employee_number' => $mapped['employee_number'] ?? null,
            'staff_name' => $mapped['staff_name'] ?? null,
            'department' => $mapped['department'] ?? null,
            'designation' => $mapped['designation'] ?? null,
            'division' => $mapped['division'] ?? null,
            'org_level' => $mapped['org_level'] ?? 'gap',
            'function_slug' => $mapped['function_slug'] ?? 'gap',
            'sector_tags' => $sectors,
            'gap_reason' => $mapped['gap_reason'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function previewRepCodeReconciliation(User $user, array $payload, array &$stats): void
    {
        $employeeNumber = $this->normalizeRepCode($payload['employee_number'] ?? null);
        $repCode = $this->normalizeRepCode($user->rep_code);
        if ($employeeNumber === null) {
            return;
        }
        if ($repCode === null && $this->isKnownRepCodePattern($employeeNumber)) {
            $stats['rep_code_backfilled']++;
        } elseif ($repCode !== null && $repCode !== $employeeNumber) {
            $stats['rep_code_conflicts']++;
            $stats['gaps']++;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $sourceRow */
    private function reconcileRepCode(User $user, array $payload, array $sourceRow, array &$stats): void
    {
        $employeeNumber = $this->normalizeRepCode($payload['employee_number'] ?? null);
        $repCode = $this->normalizeRepCode($user->rep_code);
        if ($employeeNumber === null || $repCode === $employeeNumber) {
            return;
        }

        if ($repCode === null && $this->isKnownRepCodePattern($employeeNumber)) {
            $user->forceFill(['rep_code' => $employeeNumber])->save();
            UserRepCodeHistory::create([
                'user_id' => $user->id,
                'rep_code' => $employeeNumber,
                'changed_by_name' => 'Staff import',
                'changed_by' => null,
                'change_reason' => 'staff_import_backfill',
                'changed_at' => now(),
            ]);
            $stats['rep_code_backfilled']++;
            return;
        }

        if ($repCode !== null) {
            UserAcumaticaRepMapping::query()->updateOrCreate(
                ['user_id' => $user->id, 'acumatica_rep_code' => $employeeNumber],
                ['is_primary' => false],
            );
            StaffImportGap::query()->updateOrCreate(
                [
                    'email' => $user->email,
                    'employee_number' => $employeeNumber,
                    'gap_reason' => 'rep_code_employee_number_mismatch',
                    'resolution_status' => 'open',
                ],
                [
                    'display_name' => $user->name,
                    'match_score' => 1.0,
                    'source_payload' => array_merge($sourceRow, [
                        'user_id' => $user->id,
                        'existing_rep_code' => $repCode,
                    ]),
                ],
            );
            $stats['rep_code_conflicts']++;
            $stats['gaps']++;
        }
    }

    private function normalizeRepCode(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return $value === '' ? null : $value;
    }

    private function isKnownRepCodePattern(string $value): bool
    {
        return preg_match('/^(?:P\d{3,}|C\d{3,})$/', $value) === 1;
    }

    private function resolveDepartmentId(string $functionSlug, string $departmentName = ''): ?int
    {
        $slugMap = [
            'mt_consumer_sales' => 'mt_consumer_sales',
            'gt' => 'gt',
            'kp' => 'kp',
            'partner_brands' => 'partner_brands',
            'customer_service' => 'customer_service',
            'finance' => 'finance',
            'marketing' => 'marketing',
            'procurement' => 'procurement',
            'production' => 'production',
            'stores' => 'stores',
            'dispatch' => 'dispatch',
            'hr' => 'hr',
            'it' => 'it',
        ];

        $slug = $slugMap[$functionSlug] ?? null;
        if ($slug !== null) {
            $id = Department::query()->where('slug', $slug)->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        $departmentName = trim($departmentName);
        if ($departmentName === '') {
            return null;
        }

        return Department::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($departmentName)])
            ->value('id');
    }

    private function mapDepartmentRole(string $orgLevel): string
    {
        return match ($orgLevel) {
            'executive', 'c_suite' => 'executive',
            'hod' => 'hod',
            default => 'member',
        };
    }

    private function mapProductType(string $functionSlug): string
    {
        return config("departments.default_product_type_by_function.{$functionSlug}")
            ?? ($functionSlug === 'partner_brands' ? 'trading' : 'both');
    }

    /** @param  list<string>  $sectorTags */
    private function mapSectorScopes(array $sectorTags, string $orgLevel, ?int $departmentId): array
    {
        if ($sectorTags !== []) {
            return array_map('strtoupper', $sectorTags);
        }

        return $this->userOrg->defaultSectorsForOrgLevel($orgLevel, $departmentId);
    }

    private function inferAppRole(string $orgLevel): string
    {
        return match ($orgLevel) {
            'executive', 'c_suite' => 'Executive',
            'operations' => 'Sales Operations',
            'sales', 'brandsops' => 'Sales Consultant',
            default => 'Sales Operations',
        };
    }
}
