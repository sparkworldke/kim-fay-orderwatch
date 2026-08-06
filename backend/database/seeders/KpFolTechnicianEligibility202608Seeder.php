<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class KpFolTechnicianEligibility202608Seeder extends Seeder
{
    private const EMPLOYEE_NUMBERS = ['P051', 'P163', 'P369'];

    public function run(): void
    {
        $technicianRole = Role::query()->where('name', 'Technician')->first();
        if (! $technicianRole) {
            throw new RuntimeException('The Technician app-role is missing. Run RolesPermissionsSeeder first.');
        }

        $attached = 0;
        $missing = [];

        foreach (self::EMPLOYEE_NUMBERS as $employeeNumber) {
            $user = User::query()->where('employee_number', $employeeNumber)->first();
            if (! $user) {
                $missing[] = $employeeNumber;

                continue;
            }

            $alreadyEligible = $user->roles()->whereKey($technicianRole->id)->exists();
            $user->roles()->syncWithoutDetaching([
                $technicianRole->id => ['assigned_by' => null],
            ]);
            $attached += $alreadyEligible ? 0 : 1;
        }

        $this->command?->info("KP FOL technician eligibility: {$attached} Technician roles attached.");
        if ($missing !== []) {
            $this->command?->warn('KP technicians not found by employee_number: '.implode(', ', $missing));
        }
    }
}
