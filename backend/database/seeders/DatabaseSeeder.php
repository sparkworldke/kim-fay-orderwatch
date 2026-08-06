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
            // Correct employee_number / rep_code by name from Active staff + HODs JSON:
            // UserIdentityFromStaffJsonSeeder::class,
            // Then KP portfolio import (needs rep codes aligned):
            // KpRepCodeAlignment202608Seeder::class,
            // KpCustomerPortfolio202608Seeder::class,
            // GeneralTradeHierarchy202608Seeder::class, // after UserIdentityFromStaffJsonSeeder
            // ExecutiveIdentity202608Seeder::class,
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
