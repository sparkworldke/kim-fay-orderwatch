<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            RolesPermissionsSeeder::class,
            DepartmentSeeder::class,
            ShippingZoneSeeder::class,
            RouteSeeder::class,
            UserRepCodeSeeder::class,
            CustomerSeeder::class,
            // Optional, after the employee roster exists:
            // PartnerBrandsTeam202608Seeder::class,
            // Optional: apply Products-With Brands.csv → inventory brand/product_type
            // InventoryBrandSeeder::class,
            // Optional team seats (safe updateOrCreate by email / payroll code):
            // BrandOperationsPricillahSeeder::class,
            // Optional: MSI / safety / buffer / machines from stocks-production Excel:
            // ProductionPlanningStocksSeeder::class,
        ]);
    }
}
