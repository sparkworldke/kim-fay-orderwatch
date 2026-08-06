<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Cache\DomainCache;
use Illuminate\Database\Seeder;

class ExecutiveIdentity202608Seeder extends Seeder
{
    private const EXECUTIVES = [
        ['email' => 'rbains@kimfay.com', 'name' => 'Rajdeep Singh Bains', 'employee_number' => 'P301'],
        ['email' => 'hbains@kimfay.com', 'name' => 'Hartaj Singh Bains', 'employee_number' => 'P302'],
        ['email' => 'djumani@kimfay.com', 'name' => 'Divya Sudhir Jumani', 'employee_number' => 'C1144'],
    ];

    public function run(): void
    {
        foreach (self::EXECUTIVES as $identity) {
            $user = User::query()->where('email', $identity['email'])
                ->orWhere('employee_number', $identity['employee_number'])
                ->orWhereRaw('LOWER(TRIM(name)) = ?', [strtolower($identity['name'])])
                ->first();
            if (! $user) {
                $this->command?->warn("Executive account not found: {$identity['name']} ({$identity['email']}). Login accounts are not created with guessed passwords.");
                continue;
            }
            $user->forceFill([
                'email' => $identity['email'],
                'employee_number' => $identity['employee_number'],
                'role' => 'Executive',
                'org_level' => 'executive',
                'department_role' => 'executive',
                'data_scope_mode' => 'org_wide',
                'is_active' => true,
            ])->save();
        }
        app(DomainCache::class)->bump(DomainCache::CAPABILITIES, DomainCache::REFERENCES);
    }
}
