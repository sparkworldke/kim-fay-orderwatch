<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class KpPortfolioAlignment202608Seeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            KpRepCodeAlignment202608Seeder::class,
            KpReportingHierarchy202608Seeder::class,
            KpFolTechnicianEligibility202608Seeder::class,
            KpCustomerPortfolio202608Seeder::class,
        ]);
    }
}
