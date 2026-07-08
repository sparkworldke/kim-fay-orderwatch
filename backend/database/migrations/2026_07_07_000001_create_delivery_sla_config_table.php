<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_sla_config', function (Blueprint $table) {
            $table->id();
            $table->string('region_key', 50)->unique();
            $table->string('label', 100);
            $table->unsignedSmallInteger('sla_hours');
            $table->unsignedSmallInteger('warning_hours')->nullable();
            $table->unsignedSmallInteger('breach_hours')->nullable();
            $table->boolean('is_metro')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('alert_min_orders')->default(10);
            $table->decimal('alert_delayed_pct', 5, 2)->default(15.00);
            $table->string('clock_start', 32)->default('approved_at');
            $table->timestamps();
        });

        $now = now();
        foreach ($this->defaults() as $row) {
            DB::table('delivery_sla_config')->insert([
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function defaults(): array
    {
        return [
            [
                'region_key' => 'nairobi',
                'label' => 'Nairobi',
                'sla_hours' => 24,
                'warning_hours' => null,
                'breach_hours' => 24,
                'is_metro' => true,
                'is_active' => true,
                'alert_min_orders' => 10,
                'alert_delayed_pct' => 15.00,
                'clock_start' => 'approved_at',
            ],
            [
                'region_key' => 'coast',
                'label' => 'Coast / MSA',
                'sla_hours' => 24,
                'warning_hours' => null,
                'breach_hours' => 24,
                'is_metro' => true,
                'is_active' => true,
                'alert_min_orders' => 10,
                'alert_delayed_pct' => 15.00,
                'clock_start' => 'approved_at',
            ],
            [
                'region_key' => 'other',
                'label' => 'Other regions',
                'sla_hours' => 72,
                'warning_hours' => 48,
                'breach_hours' => 72,
                'is_metro' => false,
                'is_active' => true,
                'alert_min_orders' => 10,
                'alert_delayed_pct' => 15.00,
                'clock_start' => 'approved_at',
            ],
        ];
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_sla_config');
    }
};