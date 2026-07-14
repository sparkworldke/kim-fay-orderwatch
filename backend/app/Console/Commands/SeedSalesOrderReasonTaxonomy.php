<?php

namespace App\Console\Commands;

use App\Services\Operations\SalesOrderReasonTaxonomySeeder;
use Illuminate\Console\Command;

class SeedSalesOrderReasonTaxonomy extends Command
{
    protected $signature = 'reasons:seed-taxonomy';

    protected $description = 'Persist the approved SO reason taxonomy (33 sub-reasons, parents, aliases) to the database';

    public function handle(SalesOrderReasonTaxonomySeeder $seeder): int
    {
        $result = $seeder->seed();

        $this->info(sprintf(
            'Saved reason taxonomy: %d parents, %d sub-reasons, %d parent-sub links, %d aliases.',
            $result['parents'],
            $result['sub_reasons'],
            $result['links'],
            $result['aliases'],
        ));

        return self::SUCCESS;
    }
}