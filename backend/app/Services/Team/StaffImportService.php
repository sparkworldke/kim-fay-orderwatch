<?php

namespace App\Services\Team;

use App\Models\Department;
use App\Models\OrgChartAudit;
use App\Models\StaffImportGap;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StaffImportService
{
    public function __construct(
        private readonly UserOrgService $userOrg,
        private readonly SharedMailboxPolicy $sharedMailboxPolicy,
    ) {}

    /** @return array{created: int, updated: int, skipped: int, gaps: int, errors: list<string>} */
    public function import(
        string $path,
        bool $dryRun = false,
        bool $preserveManual = true,
        string $minConfidence = 'high',
    ): array {
        $rows = $this->loadRows($path);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'gaps' => 0, 'errors' => []];

        if (! $dryRun) {
            StaffImportGap::query()
                ->where('resolution_status', 'open')
                ->whereIn('gap_reason', ['no_staff_match', 'low_confidence'])
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

            $departmentId = $this->resolveDepartmentId((string) ($row['function_slug'] ?? 'gap'));
            $orgLevel = (string) ($row['org_level'] ?? 'sales');
            $departmentRole = $this->mapDepartmentRole($orgLevel);
            $productType = $this->mapProductType((string) ($row['function_slug'] ?? ''));
            $sectorScopes = $this->mapSectorScopes($row['sector_tags'] ?? [], $orgLevel, $departmentId);

            $payload = [
                'name' => trim((string) ($row['display_name'] ?? $row['staff_name'] ?? $email)),
                'employee_number' => $row['employee_number'] ?? null,
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
                continue;
            }

            try {
                DB::transaction(function () use ($existing, $email, $payload, &$stats) {
                    if ($existing === null) {
                        $user = User::create([
                            'name' => $payload['name'],
                            'email' => $email,
                            'role' => $this->inferAppRole($payload['org_level']),
                            'employee_number' => $payload['employee_number'],
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

        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if ($rowIndex === 1) {
                $headers = array_map('strtolower', $cells);
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
        $departmentId = $this->resolveDepartmentId((string) ($payload['function_slug'] ?? 'gap'));
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
            'email' => $mapped['email'] ?? null,
            'display_name' => $mapped['display_name'] ?? null,
            'match_score' => isset($mapped['match_score']) ? (float) $mapped['match_score'] : null,
            'match_confidence' => $mapped['match_confidence'] ?? 'low',
            'employee_number' => $mapped['employee_number'] ?? null,
            'staff_name' => $mapped['staff_name'] ?? null,
            'department' => $mapped['department'] ?? null,
            'designation' => $mapped['designation'] ?? null,
            'org_level' => $mapped['org_level'] ?? 'gap',
            'function_slug' => $mapped['function_slug'] ?? 'gap',
            'sector_tags' => $sectors,
            'gap_reason' => $mapped['gap_reason'] ?? null,
        ];
    }

    private function resolveDepartmentId(string $functionSlug): ?int
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
        if ($slug === null) {
            return null;
        }

        return Department::query()->where('slug', $slug)->value('id');
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