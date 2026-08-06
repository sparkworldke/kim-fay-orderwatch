<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalReportExportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_team_role_can_export_scoped_reports(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $rolesWithoutExport = Role::query()
            ->whereDoesntHave('permissions', fn ($query) => $query->where('name', 'reports.export'))
            ->pluck('name')
            ->all();

        $this->assertSame([], $rolesWithoutExport);
        $this->assertGreaterThanOrEqual(11, Role::query()->count());
    }
}
