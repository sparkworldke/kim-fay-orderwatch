<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShippingZoneSeeder extends Seeder
{
    /**
     * Seed the acumatica_shipping_zones table with all 15 known Kim-Fay zones.
     * Uses updateOrInsert so running the seeder multiple times will create
     * missing rows and update existing ones without duplicating.
     */
    public function run(): void
    {
        $zones = [
            ['acumatica_id' => 'Z001', 'description' => 'Nairobi', 'region' => 'Nairobi'],
            ['acumatica_id' => 'Z002', 'description' => 'Nairobi', 'region' => 'Nairobi'],
            ['acumatica_id' => 'Z003', 'description' => 'Nairobi', 'region' => 'Nairobi'],
            ['acumatica_id' => 'Z004', 'description' => 'Nairobi', 'region' => 'Nairobi'],
            ['acumatica_id' => 'Z005', 'description' => 'Nairobi', 'region' => 'Nairobi'],
            ['acumatica_id' => 'Z006', 'description' => 'Mountain', 'region' => 'Central'],
            ['acumatica_id' => 'Z007', 'description' => 'Western A', 'region' => 'Western'],
            ['acumatica_id' => 'Z008', 'description' => 'Western B', 'region' => 'Western'],
            ['acumatica_id' => 'Z009', 'description' => 'Western C', 'region' => 'Western'],
            ['acumatica_id' => 'Z010', 'description' => 'Eastern', 'region' => 'Eastern'],
            ['acumatica_id' => 'Z011', 'description' => 'Voi', 'region' => 'Coast'],
            ['acumatica_id' => 'Z012', 'description' => 'Coast', 'region' => 'Coast'],
            ['acumatica_id' => 'Z013', 'description' => 'North Eastern', 'region' => 'North Eastern'],
            ['acumatica_id' => 'Z014', 'description' => 'Exports', 'region' => 'Export'],
            ['acumatica_id' => 'Z015', 'description' => 'Self Collection', 'region' => 'Self Collection'],
        ];

        $now = now();

        foreach ($zones as $zone) {
            DB::table('acumatica_shipping_zones')->updateOrInsert(
                ['acumatica_id' => $zone['acumatica_id']],
                array_merge($zone, [
                    'updated_at' => $now,
                ]),
            );
        }

        $this->command->info('ShippingZoneSeeder: ' . count($zones) . ' zones upserted.');
    }
}
