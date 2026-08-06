<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        DB::table('permissions')->insertOrIgnore([
            'name' => 'reports.export',
            'description' => 'Download scoped operational and commercial Excel reports',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')
            ->where('name', 'reports.export')
            ->update([
                'description' => 'Download scoped operational and commercial Excel reports',
                'updated_at' => now(),
            ]);

        $permissionId = DB::table('permissions')->where('name', 'reports.export')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('roles')->pluck('id')->each(function ($roleId) use ($permissionId): void {
            DB::table('role_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        });
    }

    public function down(): void
    {
        // Intentionally retain reporting access. The permission predates this
        // migration and removing role links could revoke manually granted access.
    }
};
