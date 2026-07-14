<?php

namespace App\Services\Fol;

use App\Models\FolApprovalStage;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;

/**
 * Dynamic FOL configuration: DB (system_settings + fol_approval_stages)
 * overrides config/fol.php defaults so Admins can edit without code deploys.
 */
class FolSettingsService
{
    public const MAIL_FROM_ADDRESS = 'fol.mail_from_address';

    public const MAIL_FROM_NAME = 'fol.mail_from_name';

    public const MAX_ATTACHMENT_KB = 'fol.max_attachment_kb';

    public const ATTACHMENT_MIMES = 'fol.attachment_mimes';

    public const INVOICING_ROLES = 'fol.invoicing_roles';

    public const CC_WATCHER_EMAILS = 'fol.cc_watcher_emails';

    public const DUPLICATE_POLICY = 'fol.duplicate_policy';

    public const CONSUMABLES_MONTHS = 'fol.consumables_months';

    public const REQUIRE_ATTACHMENT = 'fol.require_attachment';

    public const ALLOW_ADMIN_ON_ALL_STAGES = 'fol.allow_admin_on_all_stages';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'mail_from_address' => $this->mailFromAddress(),
            'mail_from_name' => $this->mailFromName(),
            'max_attachment_kb' => $this->maxAttachmentKb(),
            'attachment_mimes' => $this->attachmentMimes(),
            'invoicing_roles' => $this->invoicingRoles(),
            'cc_watcher_emails' => $this->ccWatcherEmails(),
            'duplicate_policy' => $this->duplicatePolicy(),
            'consumables_months' => $this->consumablesMonths(),
            'require_attachment' => $this->requireAttachment(),
            'allow_admin_on_all_stages' => $this->allowAdminOnAllStages(),
            'stages' => $this->stages()->map(fn (FolApprovalStage $s) => $this->presentStage($s))->values()->all(),
            'available_roles' => [
                'Administrator',
                'Executive',
                'Customer Service Manager',
                'Customer Service Agent',
                'Sales Operations',
                'Sales Consultant',
                'Technician Manager',
                'Technician',
            ],
            'defaults' => [
                'mail_from_address' => config('fol.mail_from_address'),
                'mail_from_name' => config('fol.mail_from_name'),
                'max_attachment_kb' => (int) config('fol.max_attachment_kb', 15360),
                'attachment_mimes' => config('fol.attachment_mimes', []),
                'invoicing_roles' => config('fol.invoicing_roles', [
                    'Administrator',
                    'Customer Service Manager',
                    'Customer Service Agent',
                    'Sales Operations',
                ]),
            ],
        ];
    }

    public function mailFromAddress(): string
    {
        return (string) (SystemSetting::valueFor(self::MAIL_FROM_ADDRESS)
            ?? config('fol.mail_from_address', 'kp@fayshop.co.ke'));
    }

    public function mailFromName(): string
    {
        return (string) (SystemSetting::valueFor(self::MAIL_FROM_NAME)
            ?? config('fol.mail_from_name', 'FOL KP Approvals'));
    }

    public function maxAttachmentKb(): int
    {
        $v = SystemSetting::valueFor(self::MAX_ATTACHMENT_KB);

        return $v !== null ? max(100, (int) $v) : (int) config('fol.max_attachment_kb', 15360);
    }

    /** @return list<string> */
    public function attachmentMimes(): array
    {
        $raw = SystemSetting::valueFor(self::ATTACHMENT_MIMES);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_map('strval', $decoded));
            }
        }

        return array_values(array_map('strval', config('fol.attachment_mimes', ['pdf', 'xlsx', 'jpg', 'png'])));
    }

    /** @return list<string> */
    public function invoicingRoles(): array
    {
        $raw = SystemSetting::valueFor(self::INVOICING_ROLES);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_map('strval', $decoded));
            }
        }

        return array_values(array_map('strval', config('fol.invoicing_roles', [
            'Administrator',
            'Customer Service Manager',
            'Customer Service Agent',
            'Sales Operations',
        ])));
    }

    /** @return list<string> */
    public function ccWatcherEmails(): array
    {
        $raw = SystemSetting::valueFor(self::CC_WATCHER_EMAILS);
        if (! $raw) {
            return array_values(array_map('strval', config('fol.cc_watcher_emails', [])));
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $decoded,
        )));
    }

    public function duplicatePolicy(): string
    {
        $v = SystemSetting::valueFor(self::DUPLICATE_POLICY);

        return in_array($v, ['block', 'warn', 'allow'], true)
            ? $v
            : (string) config('fol.duplicate_policy', 'warn');
    }

    public function consumablesMonths(): int
    {
        $v = SystemSetting::valueFor(self::CONSUMABLES_MONTHS);

        return $v !== null ? max(1, min(24, (int) $v)) : (int) config('fol.consumables_months', 6);
    }

    public function requireAttachment(): bool
    {
        $v = SystemSetting::valueFor(self::REQUIRE_ATTACHMENT);
        if ($v === null) {
            return (bool) config('fol.require_attachment', true);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function allowAdminOnAllStages(): bool
    {
        $v = SystemSetting::valueFor(self::ALLOW_ADMIN_ON_ALL_STAGES);
        if ($v === null) {
            return (bool) config('fol.allow_admin_on_all_stages', true);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return Collection<int, FolApprovalStage> */
    public function stages(bool $activeOnly = false): Collection
    {
        $q = FolApprovalStage::query()->orderBy('sort_order')->orderBy('id');
        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSettings(array $payload): array
    {
        if (array_key_exists('mail_from_address', $payload)) {
            SystemSetting::setValue(self::MAIL_FROM_ADDRESS, trim((string) $payload['mail_from_address']));
        }
        if (array_key_exists('mail_from_name', $payload)) {
            SystemSetting::setValue(self::MAIL_FROM_NAME, trim((string) $payload['mail_from_name']));
        }
        if (array_key_exists('max_attachment_kb', $payload)) {
            SystemSetting::setValue(self::MAX_ATTACHMENT_KB, (string) max(100, (int) $payload['max_attachment_kb']));
        }
        if (array_key_exists('attachment_mimes', $payload) && is_array($payload['attachment_mimes'])) {
            $mimes = array_values(array_unique(array_filter(array_map(
                fn ($m) => strtolower(trim((string) $m)),
                $payload['attachment_mimes'],
            ))));
            SystemSetting::setValue(self::ATTACHMENT_MIMES, json_encode($mimes));
        }
        if (array_key_exists('invoicing_roles', $payload) && is_array($payload['invoicing_roles'])) {
            $roles = array_values(array_unique(array_filter(array_map(
                fn ($r) => trim((string) $r),
                $payload['invoicing_roles'],
            ))));
            SystemSetting::setValue(self::INVOICING_ROLES, json_encode($roles));
        }
        if (array_key_exists('cc_watcher_emails', $payload) && is_array($payload['cc_watcher_emails'])) {
            $emails = array_values(array_unique(array_filter(array_map(
                fn ($e) => strtolower(trim((string) $e)),
                $payload['cc_watcher_emails'],
            ))));
            SystemSetting::setValue(self::CC_WATCHER_EMAILS, json_encode($emails));
        }
        if (array_key_exists('duplicate_policy', $payload)) {
            $p = (string) $payload['duplicate_policy'];
            if (in_array($p, ['block', 'warn', 'allow'], true)) {
                SystemSetting::setValue(self::DUPLICATE_POLICY, $p);
            }
        }
        if (array_key_exists('consumables_months', $payload)) {
            SystemSetting::setValue(self::CONSUMABLES_MONTHS, (string) max(1, min(24, (int) $payload['consumables_months'])));
        }
        if (array_key_exists('require_attachment', $payload)) {
            SystemSetting::setValue(self::REQUIRE_ATTACHMENT, $payload['require_attachment'] ? '1' : '0');
        }
        if (array_key_exists('allow_admin_on_all_stages', $payload)) {
            SystemSetting::setValue(self::ALLOW_ADMIN_ON_ALL_STAGES, $payload['allow_admin_on_all_stages'] ? '1' : '0');
        }

        return $this->all();
    }

    /**
     * Replace/upsert approval stages from admin UI (dynamic chain).
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    public function syncStages(array $stages): array
    {
        $keptIds = [];

        foreach (array_values($stages) as $index => $row) {
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?: ('stage_'.($index + 1));

            $roleNames = array_values(array_unique(array_filter(array_map(
                fn ($r) => trim((string) $r),
                is_array($row['role_names'] ?? null) ? $row['role_names'] : [],
            ))));
            $userIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($row['user_ids'] ?? null) ? $row['user_ids'] : [],
            ))));

            $stage = FolApprovalStage::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => trim((string) ($row['name'] ?? $key)) ?: $key,
                    'sort_order' => (int) ($row['sort_order'] ?? ($index + 1)),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'assignee_mode' => in_array($row['assignee_mode'] ?? 'role', ['role', 'user_list', 'manager_of_submitter'], true)
                        ? $row['assignee_mode']
                        : 'role',
                    'role_names' => $roleNames,
                    'user_ids' => $userIds,
                    'require_comment' => (bool) ($row['require_comment'] ?? true),
                    'sla_hours' => isset($row['sla_hours']) ? max(1, (int) $row['sla_hours']) : null,
                ],
            );
            $keptIds[] = $stage->id;
        }

        if ($keptIds !== []) {
            FolApprovalStage::query()->whereNotIn('id', $keptIds)->delete();
        }

        return $this->stages()->map(fn (FolApprovalStage $s) => $this->presentStage($s))->values()->all();
    }

    /** @return array<string, mixed> */
    private function presentStage(FolApprovalStage $stage): array
    {
        return [
            'id' => $stage->id,
            'key' => $stage->key,
            'name' => $stage->name,
            'sort_order' => $stage->sort_order,
            'is_active' => (bool) $stage->is_active,
            'assignee_mode' => $stage->assignee_mode,
            'role_names' => $stage->role_names ?? [],
            'user_ids' => $stage->user_ids ?? [],
            'require_comment' => (bool) $stage->require_comment,
            'sla_hours' => $stage->sla_hours,
        ];
    }
}
