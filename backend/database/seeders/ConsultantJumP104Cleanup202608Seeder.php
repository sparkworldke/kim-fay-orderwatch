<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserCustomerAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConsultantJumP104Cleanup202608Seeder extends Seeder
{
    private const DUPLICATE_EMAIL = 'npd@kimfay.com'; // placeholder "Consultant JUM" account

    private const KEEP_EMAIL = 'iluketelo@kimfay.com'; // Irene Naliaka Luketelo, real P104 owner

    public function run(): void
    {
        $keep = User::query()->where('email', self::KEEP_EMAIL)->first();
        if (! $keep) {
            throw new RuntimeException('Irene Naliaka Luketelo ('.self::KEEP_EMAIL.') not found; cannot proceed.');
        }

        $duplicate = User::query()->where('email', self::DUPLICATE_EMAIL)->first();
        if (! $duplicate) {
            $this->command?->info('Consultant JUM ('.self::DUPLICATE_EMAIL.') already removed; nothing to do.');

            return;
        }

        if ($duplicate->id === $keep->id) {
            throw new RuntimeException('Duplicate and keeper resolved to the same user; aborting.');
        }

        DB::transaction(function () use ($duplicate, $keep): void {
            foreach (UserCustomerAssignment::query()->where('user_id', $duplicate->id)->get() as $assignment) {
                $collides = UserCustomerAssignment::query()
                    ->where('user_id', $keep->id)
                    ->where('customer_acumatica_id', $assignment->customer_acumatica_id)
                    ->where('assignment_type', $assignment->assignment_type)
                    ->exists();

                $collides ? $assignment->delete() : $assignment->update(['user_id' => $keep->id]);
            }

            User::query()->where('reports_to_user_id', $duplicate->id)->update(['reports_to_user_id' => $keep->id]);

            foreach (UserAcumaticaRepMapping::query()->where('user_id', $duplicate->id)->get() as $mapping) {
                $collides = UserAcumaticaRepMapping::query()
                    ->where('user_id', $keep->id)
                    ->where('acumatica_rep_code', $mapping->acumatica_rep_code)
                    ->where('acumatica_consultant_id', $mapping->acumatica_consultant_id)
                    ->exists();

                $collides ? $mapping->delete() : $mapping->update(['user_id' => $keep->id]);
            }

            foreach (DB::table('user_roles')->where('user_id', $duplicate->id)->get() as $userRole) {
                $collides = DB::table('user_roles')
                    ->where('user_id', $keep->id)
                    ->where('role_id', $userRole->role_id)
                    ->exists();

                if ($collides) {
                    DB::table('user_roles')->where('id', $userRole->id)->delete();
                } else {
                    DB::table('user_roles')->where('id', $userRole->id)->update(['user_id' => $keep->id]);
                }
            }

            $blocking = $this->findOtherReferencingTables($duplicate->id);
            if ($blocking !== []) {
                throw new RuntimeException(
                    'Cannot delete Consultant JUM (#'.$duplicate->id.'): still referenced by '.
                    implode(', ', array_map(
                        static fn (string $table, int $count) => "{$table} ({$count} rows)",
                        array_keys($blocking),
                        $blocking,
                    )).
                    '. Reassign or clear those rows manually, then rerun this seeder.'
                );
            }

            $duplicate->delete();
        });

        $this->command?->info("Deleted Consultant JUM (#{$duplicate->id}); portfolio and reportees reassigned to Irene Naliaka Luketelo (#{$keep->id}).");
    }

    /** @return array<string, int> table name => row count still referencing this user id */
    private function findOtherReferencingTables(int $userId): array
    {
        $skip = ['users', 'user_customer_assignments', 'user_acumatica_rep_mappings', 'user_roles'];

        $tables = DB::select(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'"
        );

        $blocking = [];
        foreach ($tables as $row) {
            $table = $row->TABLE_NAME;
            if (in_array($table, $skip, true) || ! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->where('user_id', $userId)->count();
            if ($count > 0) {
                $blocking[$table] = $count;
            }
        }

        return $blocking;
    }
}
