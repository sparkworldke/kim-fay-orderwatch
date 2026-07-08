<?php

use App\Models\CronJob;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CronJob::query()
            ->where('job_key', 'acumatica-customer-category-sync')
            ->delete();
    }

    public function down(): void
    {
        CronJob::customerCategorySync();
    }
};