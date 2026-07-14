<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $users = DB::table('users')
            ->whereNotNull('department_id')
            ->get(['id', 'department_id', 'department_role']);

        foreach ($users as $row) {
            $exists = DB::table('department_user')
                ->where('user_id', $row->id)
                ->where('department_id', $row->department_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('department_user')->insert([
                'department_id' => $row->department_id,
                'user_id' => $row->id,
                'membership_role' => $row->department_role ?? 'member',
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive backfill — no rollback.
    }
};