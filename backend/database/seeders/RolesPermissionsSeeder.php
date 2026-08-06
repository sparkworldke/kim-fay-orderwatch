<?php

namespace Database\Seeders;

use App\Models\FolApprovalStage;
use App\Models\NotificationRule;
use App\Models\Permission;
use App\Models\PriceChangeApprovalStage;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ────────────────────────────────────────────────────────────

        $roles = [
            'Administrator',
            'Customer Service Manager',
            'Customer Service Agent',
            'Sales Operations',
            'Sales Consultant',
            'Executive',
            'HOD',
            'Technician Manager',
            'Technician',
            'Partner Brands HOD',
            'Partner Brands Member',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName],
                ['is_system' => true]
            );
        }

        // ── Permissions ──────────────────────────────────────────────────────

        $permissions = [
            'admin.view',
            'admin.api_keys',
            'admin.cron_jobs',
            'mailboxes.connect',
            'mailboxes.disconnect',
            'mailboxes.view',
            'acumatica.view',
            'acumatica.config',
            'acumatica.validate',
            'ai.view',
            'ai.keys',
            'ai.regenerate',
            'audit.view',
            'audit.export',
            'roles.view',
            'roles.manage',
            'permissions.manage',
            'notifications.view',
            'notifications.manage',
            'orders.view',
            'orders.assign',
            'orders.resolve',
            'orders.escalate',
            'customers.manage',
            'customers.assign.view',
            'customers.assign.manage',
            'customers.assign.manage_all',
            'customers.assign.export',
            'consultants.view',
            'consultants.manage',
            'reports.export',
            'email-import.manage',
            'email-import.approve',
            'email-import.create-wildcards',
            'kp.fol.view',
            'kp.fol.request',
            'kp.fol.approve',
            'kp.fol.invoice',
            'kp.fol.report',
            'kp.fol.install.manage',
            'kp.fol.install.execute',
            'pricing.pcr.view',
            'pricing.pcr.create',
            'pricing.pcr.approve',
            'pricing.pcr.approve_escalated',
            'pricing.pcr.view_margin',
            'pricing.pcr.apply_erp',
            'pricing.pcr.config',
            'sales.management.view',
            'sales.management.resolve',
            'sales.management.manage',
            'sales.management.config',
            'dtc.view',
            'dtc.quotes.create',
            'dtc.quotes.convert',
            'commissions.view_own',
            'commissions.review',
            'commissions.rules.manage',
            'commissions.approve',
            'commissions.lock',
            'kp.crm.items_not_ordered.view',
            'kp.crm.dormant.manage',
            'kp.crm.performance.view',
            'adoption.report.view',
            'adoption.report.manage',
            'production.planning.manage',
        ];

        foreach ($permissions as $slug) {
            Permission::firstOrCreate(['name' => $slug]);
        }

        $allPermissionIds = Permission::pluck('id')->all();
        $viewPermissionIds = Permission::whereIn('name', [
            'admin.view',
            'mailboxes.view',
            'acumatica.view',
            'ai.view',
            'audit.view',
            'roles.view',
            'notifications.view',
            'orders.view',
        ])->pluck('id')->all();

        Role::where('name', 'Administrator')->first()?->permissions()->sync($allPermissionIds);

        foreach (['Customer Service Manager', 'Sales Operations', 'Executive'] as $roleName) {
            Role::where('name', $roleName)->first()?->permissions()->sync($viewPermissionIds);
        }

        Role::where('name', 'Customer Service Manager')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                ...Permission::whereIn('id', $viewPermissionIds)->pluck('name')->all(),
                'consultants.view',
                'consultants.manage',
                'customers.assign.view',
                'customers.assign.manage',
                'customers.assign.export',
            ])->pluck('id')->all()
        );

        Role::where('name', 'Sales Consultant')->first()?->permissions()->sync(
            Permission::whereIn('name', ['orders.view'])->pluck('id')->all()
        );

        Role::where('name', 'Customer Service Agent')->first()?->permissions()->sync(
            Permission::whereIn('name', ['orders.view', 'orders.resolve'])->pluck('id')->all()
        );

        Role::where('name', 'Sales Consultant')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                'orders.view',
                'kp.fol.view',
                'kp.fol.request',
                'pricing.pcr.view',
                'pricing.pcr.create',
                'sales.management.view',
                'sales.management.resolve',
                'dtc.view',
                'dtc.quotes.create',
                'dtc.quotes.convert',
                'commissions.view_own',
                'kp.crm.items_not_ordered.view',
                'kp.crm.performance.view',
            ])->pluck('id')->all()
        );

        Role::where('name', 'Sales Operations')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                ...Permission::whereIn('id', $viewPermissionIds)->pluck('name')->all(),
                'kp.fol.view',
                'kp.fol.invoice',
                'kp.fol.report',
                'pricing.pcr.view',
                'pricing.pcr.view_margin',
                'pricing.pcr.apply_erp',
                'customers.assign.view',
                'customers.assign.manage',
                'customers.assign.export',
                'dtc.view',
                'dtc.quotes.create',
                'dtc.quotes.convert',
                'commissions.review',
                'commissions.rules.manage',
                'kp.crm.items_not_ordered.view',
                'kp.crm.dormant.manage',
                'kp.crm.performance.view',
            ])->pluck('id')->all()
        );

        Role::where('name', 'Executive')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                ...Permission::whereIn('id', $viewPermissionIds)->pluck('name')->all(),
                'kp.fol.view',
                'kp.fol.report',
                'pricing.pcr.view',
                'pricing.pcr.approve',
                'pricing.pcr.approve_escalated',
                'pricing.pcr.view_margin',
                'sales.management.view',
                'sales.management.resolve',
                'sales.management.manage',
                'dtc.view',
                'dtc.quotes.create',
                'dtc.quotes.convert',
                'commissions.view_own',
                'commissions.review',
                'commissions.rules.manage',
                'commissions.approve',
                'commissions.lock',
                'production.planning.manage',
                'kp.crm.items_not_ordered.view',
                'kp.crm.dormant.manage',
                'kp.crm.performance.view',
            ])->pluck('id')->all()
        );

        Role::where('name', 'Customer Service Manager')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                ...Permission::whereIn('id', $viewPermissionIds)->pluck('name')->all(),
                'consultants.view',
                'consultants.manage',
                'kp.fol.view',
                'kp.fol.invoice',
                'pricing.pcr.view',
                'pricing.pcr.approve',
                'pricing.pcr.view_margin',
                'sales.management.view',
                'sales.management.resolve',
                'sales.management.manage',
                'customers.assign.view',
                'customers.assign.manage',
                'customers.assign.export',
                'dtc.view',
                'dtc.quotes.create',
                'dtc.quotes.convert',
                'commissions.view_own',
                'commissions.review',
                'commissions.approve',
                'kp.crm.items_not_ordered.view',
                'kp.crm.dormant.manage',
                'kp.crm.performance.view',
            ])->pluck('id')->all()
        );

        Role::where('name', 'Customer Service Agent')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                'orders.view',
                'orders.resolve',
                'kp.fol.view',
                'kp.fol.invoice',
                'pricing.pcr.view',
            ])->pluck('id')->all()
        );

        Role::where('name', 'HOD')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                ...Permission::whereIn('id', $viewPermissionIds)->pluck('name')->all(),
                'consultants.view',
                'sales.management.view',
                'sales.management.resolve',
                'sales.management.manage',
                'dtc.view',
                'dtc.quotes.create',
                'dtc.quotes.convert',
            ])->pluck('id')->all()
        );

        Role::where('name', 'Technician Manager')->first()?->permissions()->sync(
            Permission::whereIn('name', ['kp.fol.view', 'kp.fol.install.manage'])->pluck('id')->all()
        );

        Role::where('name', 'Technician')->first()?->permissions()->sync(
            Permission::whereIn('name', ['kp.fol.view', 'kp.fol.install.execute'])->pluck('id')->all()
        );

        $partnerReadPermissions = Permission::whereIn('name', [
            'orders.view',
            'reports.export',
            'customers.assign.view',
            'consultants.view',
            'sales.management.view',
            'dtc.view',
            'kp.fol.view',
            'kp.crm.items_not_ordered.view',
            'kp.crm.performance.view',
        ])->pluck('id')->all();
        Role::where('name', 'Partner Brands Member')->first()?->permissions()->sync($partnerReadPermissions);
        Role::where('name', 'Partner Brands HOD')->first()?->permissions()->sync(
            Permission::whereIn('name', [
                ...Permission::whereIn('id', $partnerReadPermissions)->pluck('name')->all(),
                'customers.assign.manage',
                'customers.assign.export',
                'sales.management.resolve',
                'sales.management.manage',
                'kp.crm.dormant.manage',
            ])->pluck('id')->all()
        );

        // Reporting is a read-only platform capability. Every team role may
        // download Excel, while each report query continues to enforce the
        // user's customer, department, channel and partner-brand data scope.
        $reportExportPermissionId = Permission::where('name', 'reports.export')->value('id');
        if ($reportExportPermissionId !== null) {
            Role::query()->each(function (Role $role) use ($reportExportPermissionId): void {
                $role->permissions()->syncWithoutDetaching([$reportExportPermissionId]);
            });
        }

        User::whereNotNull('role')->get()->each(function (User $user): void {
            $role = Role::where('name', $user->role)->first();

            if (! $role) {
                return;
            }

            UserRole::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['role_id' => $role->id]
            );
        });

        // Administrator is on every stage so testing (and super-users) can
        // create → approve HOD → approve CCO and receive stage mails (N1/N3).
        FolApprovalStage::updateOrCreate(
            ['key' => 'hod'],
            [
                'name' => 'HOD Approval',
                'sort_order' => 1,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator', 'Customer Service Manager', 'Executive'],
                'user_ids' => [],
                'require_comment' => true,
                'sla_hours' => 48,
            ]
        );

        FolApprovalStage::updateOrCreate(
            ['key' => 'cco'],
            [
                'name' => 'CCO / COO Final Approval',
                'sort_order' => 2,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator', 'Executive'],
                'user_ids' => [],
                'require_comment' => true,
                'sla_hours' => 48,
            ]
        );

        // ── Notification Rules ───────────────────────────────────────────────

        PriceChangeApprovalStage::updateOrCreate(
            ['key' => 'hod'],
            [
                'name' => 'HOD / Customer Service Manager / Executive',
                'sort_order' => 1,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator', 'Customer Service Manager', 'Executive'],
                'user_ids' => [],
                'require_comment_on_reject' => true,
                'sla_hours' => 24,
            ]
        );

        PriceChangeApprovalStage::updateOrCreate(
            ['key' => 'senior'],
            [
                'name' => 'Executive / Administrator',
                'sort_order' => 2,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator', 'Executive'],
                'user_ids' => [],
                'require_comment_on_reject' => true,
                'sla_hours' => 24,
            ]
        );

        // Bulk ops emails OFF (R1–R3, R5–R6, SM-*). Workflow emails ON: FOL N1–N6, PCR P1–P6.
        // Also active outside rules: System Health [CRITICAL], Daily management report.
        $rules = [
            [
                'rule_key' => 'R1',
                'label' => 'Critical Orders Pending',
                'channels' => ['email', 'in_app'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'R2',
                'label' => 'SLA Breach',
                'channels' => ['email'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'R3',
                'label' => 'Revenue at Risk',
                'channels' => ['email'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'R4',
                'label' => 'AI Cycle Complete',
                'channels' => ['in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'R5',
                'label' => 'Order Match Queue Backlog',
                'channels' => ['email'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'R6',
                'label' => 'Order Match Duplicate PO',
                'channels' => ['email'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'FOL-N1',
                'label' => 'FOL Submitted - HOD Approval',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'FOL-N2',
                'label' => 'FOL Stage Approved - Consultant',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'FOL-N3',
                'label' => 'FOL Pending Final Approval',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'FOL-N4',
                'label' => 'FOL Fully Approved - Consultant',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'FOL-N5',
                'label' => 'FOL Approved for Invoicing',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'FOL-N6',
                'label' => 'FOL Rejected',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'PCR-P1',
                'label' => 'PCR Submitted',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'PCR-P2',
                'label' => 'PCR Stage Approved',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'PCR-P3',
                'label' => 'PCR Final Approved - Pending ERP Apply',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'PCR-P4',
                'label' => 'PCR Rejected',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'PCR-P5',
                'label' => 'PCR Marked Applied in ERP',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'PCR-P6',
                'label' => 'PCR SLA Breach',
                'channels' => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key' => 'SM-P1',
                'label' => 'Sales Management Order Cycle Due',
                'channels' => ['email', 'in_app'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'SM-P2',
                'label' => 'Sales Management Order Cycle Overdue',
                'channels' => ['email', 'in_app'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'SM-P3',
                'label' => 'Sales Management Month Close Gap',
                'channels' => ['email', 'in_app'],
                'is_enabled' => false,
            ],
            [
                'rule_key' => 'SM-P4',
                'label' => 'Sales Management Prompt Resolved Summary',
                'channels' => ['email', 'in_app'],
                'is_enabled' => false,
            ],
        ];

        foreach ($rules as $rule) {
            NotificationRule::updateOrCreate(
                ['rule_key' => $rule['rule_key']],
                [
                    'label' => $rule['label'],
                    'channels' => $rule['channels'],
                    'is_enabled' => $rule['is_enabled'],
                ]
            );
        }
    }
}
