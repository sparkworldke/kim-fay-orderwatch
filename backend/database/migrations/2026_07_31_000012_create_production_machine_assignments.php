<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_machines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });
        Schema::create('production_machine_plan', function (Blueprint $table) {
            $table->foreignId('production_sku_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_machine_id')->constrained()->cascadeOnDelete();
            $table->primary(['production_sku_plan_id', 'production_machine_id']);
        });

        DB::table('production_sku_plans')->whereNotNull('machine')->orderBy('id')->each(function ($plan) {
            $name = trim((string) $plan->machine);
            if ($name === '') return;
            $machineId = DB::table('production_machines')->where('name', $name)->value('id');
            if (! $machineId) {
                $machineId = DB::table('production_machines')->insertGetId([
                    'name' => $name, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('production_machine_plan')->insertOrIgnore([
                'production_sku_plan_id' => $plan->id,
                'production_machine_id' => $machineId,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_machine_plan');
        Schema::dropIfExists('production_machines');
    }
};
