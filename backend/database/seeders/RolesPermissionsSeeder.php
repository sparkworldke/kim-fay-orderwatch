<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use App\Models\Permission;
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
            'Executive',
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
            'reports.export',
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

        Role::where('name', 'Customer Service Agent')->first()?->permissions()->sync(
            Permission::whereIn('name', ['orders.view', 'orders.resolve'])->pluck('id')->all()
        );

        User::whereNotNull('role')->get()->each(function (User $user): void {
            $role = Role::where('name', $user->role)->first();

            if (! $role) {
                return;
            }

            UserRole::updateOrCreate(
                ['user_id' => $user->id],
                ['role_id' => $role->id]
            );
        });

        // ── Notification Rules ───────────────────────────────────────────────

        $rules = [
            [
                'rule_key'   => 'R1',
                'label'      => 'Critical Orders Pending',
                'channels'   => ['email', 'in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key'   => 'R2',
                'label'      => 'SLA Breach',
                'channels'   => ['email'],
                'is_enabled' => true,
            ],
            [
                'rule_key'   => 'R3',
                'label'      => 'Revenue at Risk',
                'channels'   => ['email'],
                'is_enabled' => true,
            ],
            [
                'rule_key'   => 'R4',
                'label'      => 'AI Cycle Complete',
                'channels'   => ['in_app'],
                'is_enabled' => true,
            ],
            [
                'rule_key'   => 'R5',
                'label'      => 'Order Match Queue Backlog',
                'channels'   => ['email'],
                'is_enabled' => true,
            ],
            [
                'rule_key'   => 'R6',
                'label'      => 'Order Match Duplicate PO',
                'channels'   => ['email'],
                'is_enabled' => true,
            ],
        ];

        foreach ($rules as $rule) {
            NotificationRule::firstOrCreate(
                ['rule_key' => $rule['rule_key']],
                [
                    'label'      => $rule['label'],
                    'channels'   => $rule['channels'],
                    'is_enabled' => $rule['is_enabled'],
                ]
            );
        }
    }
}
