<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{name: string, region: string}> */
    private const KNOWN_ZONES = [
        'Z001' => ['name' => 'Westlands', 'region' => 'Nairobi'],
        'Z002' => ['name' => 'CBD', 'region' => 'Nairobi'],
        'Z003' => ['name' => 'Ngong', 'region' => 'Nairobi'],
        'Z004' => ['name' => 'Thika', 'region' => 'Nairobi'],
        'Z005' => ['name' => 'Mombasa Rd', 'region' => 'Nairobi'],
        'Z012' => ['name' => 'Mombasa', 'region' => 'Coast'],
    ];

    public function up(): void
    {
        Schema::table('acumatica_shipping_zones', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('description');
            $table->string('region', 50)->nullable()->after('name');
        });

        foreach (self::KNOWN_ZONES as $zoneId => $meta) {
            DB::table('acumatica_shipping_zones')
                ->where('acumatica_id', $zoneId)
                ->update([
                    'name' => $meta['name'],
                    'region' => $meta['region'],
                    'updated_at' => now(),
                ]);
        }

        $now = now();
        foreach (self::KNOWN_ZONES as $zoneId => $meta) {
            $exists = DB::table('acumatica_shipping_zones')->where('acumatica_id', $zoneId)->exists();
            if ($exists) {
                continue;
            }

            DB::table('acumatica_shipping_zones')->insert([
                'acumatica_id' => $zoneId,
                'description' => null,
                'name' => $meta['name'],
                'region' => $meta['region'],
                'sync_run_id' => null,
                'synced_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('acumatica_shipping_zones', function (Blueprint $table) {
            $table->dropColumn(['name', 'region']);
        });
    }
};