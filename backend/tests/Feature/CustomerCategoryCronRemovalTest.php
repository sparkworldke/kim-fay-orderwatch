<?php

namespace Tests\Feature;

use App\Console\Commands\SyncAcumaticaCustomerCategories;
use App\Models\CronJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCategoryCronRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_category_sync_is_not_registered_in_default_cron_jobs(): void
    {
        CronJob::ensureDefaults();

        $this->assertDatabaseMissing('cron_jobs', [
            'job_key' => 'acumatica-customer-category-sync',
        ]);
    }

    public function test_customer_category_sync_command_still_exists_for_manual_use(): void
    {
        $this->assertTrue(class_exists(SyncAcumaticaCustomerCategories::class));
    }
}