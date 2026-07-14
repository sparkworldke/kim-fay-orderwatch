<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RouteSeeder extends Seeder
{
    /**
     * Seed the acumatica_routes table with all 129 Kim-Fay routes.
     * Each route is linked to its parent shipping zone via shipping_zone_id.
     * Uses updateOrInsert so running the seeder multiple times will create
     * missing rows and update existing ones without duplicating.
     */
    public function run(): void
    {
        $routes = [
            ['route_code' => '1A', 'route_name' => 'Kikuyu Town', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1B', 'route_name' => 'Waiyaki Way', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1C', 'route_name' => 'Lower Kabete', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1D', 'route_name' => 'Westlands', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1E', 'route_name' => 'Highridge', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1F', 'route_name' => 'Parklands', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1G', 'route_name' => 'Pangani', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1H', 'route_name' => 'Ngara', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '1I', 'route_name' => 'Limuru', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1K', 'route_name' => 'Village Market', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1L', 'route_name' => 'Gigiri', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '1M', 'route_name' => 'Muthaiga', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '1N', 'route_name' => 'Ruaka', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '2A', 'route_name' => 'CBD', 'shipping_zone_id' => 'Z002', 'customer_zone' => 'CBD'],
            ['route_code' => '2B', 'route_name' => 'Kijabe', 'shipping_zone_id' => 'Z002', 'customer_zone' => 'CBD'],
            ['route_code' => '2C', 'route_name' => 'Uhuru Highway', 'shipping_zone_id' => 'Z002', 'customer_zone' => 'CBD'],
            ['route_code' => '2D', 'route_name' => 'Biashara Street', 'shipping_zone_id' => 'Z002', 'customer_zone' => 'CBD'],
            ['route_code' => '3A', 'route_name' => 'Ngong', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3B', 'route_name' => 'Karen', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3C', 'route_name' => 'Ngong Road', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3D', 'route_name' => 'Community', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3E', 'route_name' => 'Kiserian', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3F', 'route_name' => 'Ongata Rongai', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3G', 'route_name' => 'Langata', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3H', 'route_name' => 'Wilson Airport', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3I', 'route_name' => 'Mbagathi Road', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3J', 'route_name' => 'Madaraka', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3K', 'route_name' => 'Lavington', 'shipping_zone_id' => 'Z001', 'customer_zone' => 'Westlands'],
            ['route_code' => '3L', 'route_name' => 'Hurlingham', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3M', 'route_name' => 'Kilimani', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3N', 'route_name' => 'Milimani', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3O', 'route_name' => 'Upper Hill', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '3P', 'route_name' => 'Kawangware', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3Q', 'route_name' => 'Valley Road', 'shipping_zone_id' => 'Z003', 'customer_zone' => 'Ngong'],
            ['route_code' => '3R', 'route_name' => 'Langata Road', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '4A', 'route_name' => 'Thika Town', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '4B', 'route_name' => 'Kahawa', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '4C', 'route_name' => 'Thika Road', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '4D', 'route_name' => 'Zimmerman', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '4E', 'route_name' => 'Kiambu', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '4F', 'route_name' => 'Runda', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '4G', 'route_name' => 'Ruiru', 'shipping_zone_id' => 'Z004', 'customer_zone' => 'Thika'],
            ['route_code' => '5A', 'route_name' => 'Industrial Area', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5B', 'route_name' => 'Pipeline', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5C', 'route_name' => 'Embakasi', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5D', 'route_name' => 'JKIA', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5E', 'route_name' => 'Eastleigh', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5F', 'route_name' => 'Kariobangi south', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5G', 'route_name' => 'Buru buru', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5H', 'route_name' => 'Umoja', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5I', 'route_name' => 'Kayole', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5K', 'route_name' => 'Donholm', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5L', 'route_name' => 'Jogoo road', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5M', 'route_name' => 'Nairobi West', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5N', 'route_name' => 'South C', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5O', 'route_name' => 'South B', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5P', 'route_name' => 'Mombasa Road', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5Q', 'route_name' => 'Athi River', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5R', 'route_name' => 'Kitengela', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5S', 'route_name' => 'Kangundo Road', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5T', 'route_name' => 'Utawala', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '5U', 'route_name' => 'Isinya', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '6A', 'route_name' => 'Nyeri', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6B', 'route_name' => "Murang'a", 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6C', 'route_name' => 'Embu', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6D', 'route_name' => 'Isiolo', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6E', 'route_name' => 'Nanyuki', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6F', 'route_name' => 'Meru', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6G', 'route_name' => 'Maua', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6H', 'route_name' => 'Karatina', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6I', 'route_name' => 'Kenol', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6J', 'route_name' => 'Chuka', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6M', 'route_name' => 'Kerugoya', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6N', 'route_name' => 'Sagana', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6O', 'route_name' => 'Laare', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6P', 'route_name' => 'Mwea', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '6Q', 'route_name' => 'Marsabit', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '7A', 'route_name' => 'Naivasha', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7B', 'route_name' => 'Nyahururu', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7C', 'route_name' => 'Nakuru', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7D', 'route_name' => 'Molo', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7E', 'route_name' => 'Kericho', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7F', 'route_name' => 'Kisumu', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7G', 'route_name' => 'Kakamega', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7H', 'route_name' => 'Busia', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '7J', 'route_name' => 'Siaya', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '8A', 'route_name' => 'Eldoret', 'shipping_zone_id' => 'Z007', 'customer_zone' => 'Western A'],
            ['route_code' => '8B', 'route_name' => 'Nandi Hills', 'shipping_zone_id' => 'Z008', 'customer_zone' => 'Western B'],
            ['route_code' => '8D', 'route_name' => 'Baringo', 'shipping_zone_id' => 'Z008', 'customer_zone' => 'Western B'],
            ['route_code' => '8E', 'route_name' => 'Kapsabet', 'shipping_zone_id' => 'Z008', 'customer_zone' => 'Western B'],
            ['route_code' => '8F', 'route_name' => 'Kitale', 'shipping_zone_id' => 'Z012', 'customer_zone' => 'Coast'],
            ['route_code' => '8G', 'route_name' => 'Bungoma', 'shipping_zone_id' => 'Z008', 'customer_zone' => 'Western B'],
            ['route_code' => '9A', 'route_name' => 'Narok', 'shipping_zone_id' => 'Z008', 'customer_zone' => 'Western B'],
            ['route_code' => '9B', 'route_name' => 'Bomet', 'shipping_zone_id' => 'Z009', 'customer_zone' => 'Western C'],
            ['route_code' => '9D', 'route_name' => 'Kisii', 'shipping_zone_id' => 'Z009', 'customer_zone' => 'Western C'],
            ['route_code' => '9F', 'route_name' => 'Homa Bay', 'shipping_zone_id' => 'Z009', 'customer_zone' => 'Western C'],
            ['route_code' => '9G', 'route_name' => 'Migori', 'shipping_zone_id' => 'Z009', 'customer_zone' => 'Western C'],
            ['route_code' => '9H', 'route_name' => 'Mahi Mahiu', 'shipping_zone_id' => 'Z009', 'customer_zone' => 'Western C'],
            ['route_code' => '10A', 'route_name' => 'Machakos', 'shipping_zone_id' => 'Z005', 'customer_zone' => 'Mombasa Road'],
            ['route_code' => '10B', 'route_name' => 'Wote', 'shipping_zone_id' => 'Z010', 'customer_zone' => 'Eastern'],
            ['route_code' => '10C', 'route_name' => 'Matuu', 'shipping_zone_id' => 'Z010', 'customer_zone' => 'Eastern'],
            ['route_code' => '10D', 'route_name' => 'Mwingi', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '10E', 'route_name' => 'Kitui', 'shipping_zone_id' => 'Z010', 'customer_zone' => 'Eastern'],
            ['route_code' => '10F', 'route_name' => 'Kibwezi', 'shipping_zone_id' => 'Z010', 'customer_zone' => 'Eastern'],
            ['route_code' => '10K', 'route_name' => 'Makindu', 'shipping_zone_id' => 'Z010', 'customer_zone' => 'Eastern'],
            ['route_code' => '11A', 'route_name' => 'Voi', 'shipping_zone_id' => 'Z012', 'customer_zone' => 'Coast'],
            ['route_code' => '11B', 'route_name' => 'Taita', 'shipping_zone_id' => 'Z011', 'customer_zone' => 'Voi'],
            ['route_code' => '12A', 'route_name' => 'Mombasa', 'shipping_zone_id' => 'Z012', 'customer_zone' => 'Coast'],
            ['route_code' => '12B', 'route_name' => 'Diani', 'shipping_zone_id' => 'Z012', 'customer_zone' => 'Coast'],
            ['route_code' => '12C', 'route_name' => 'Lamu', 'shipping_zone_id' => 'Z012', 'customer_zone' => 'Coast'],
            ['route_code' => '12D', 'route_name' => 'Malindi', 'shipping_zone_id' => 'Z012', 'customer_zone' => 'Coast'],
            ['route_code' => '13A', 'route_name' => 'Kakuma', 'shipping_zone_id' => 'Z013', 'customer_zone' => 'North Eastern'],
            ['route_code' => '13B', 'route_name' => 'Garissa', 'shipping_zone_id' => 'Z013', 'customer_zone' => 'North Eastern'],
            ['route_code' => '13C', 'route_name' => 'Lodwar', 'shipping_zone_id' => 'Z013', 'customer_zone' => 'North Eastern'],
            ['route_code' => '14A', 'route_name' => 'Tanzania', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '14B', 'route_name' => 'Zanzibar', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '14C', 'route_name' => 'Uganda', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '14D', 'route_name' => 'Mauritius', 'shipping_zone_id' => 'Z015', 'customer_zone' => 'Self Collection'],
            ['route_code' => '14E', 'route_name' => 'Nairobi (Airfreight)', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '14F', 'route_name' => 'Rwanda', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '14G', 'route_name' => 'Malawi', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '14H', 'route_name' => 'Harare', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => '16A', 'route_name' => 'Nakuru', 'shipping_zone_id' => null, 'customer_zone' => null],
            ['route_code' => '39B', 'route_name' => 'Maua', 'shipping_zone_id' => 'Z006', 'customer_zone' => 'Mountain'],
            ['route_code' => '51A', 'route_name' => 'Kitui', 'shipping_zone_id' => 'Z010', 'customer_zone' => 'Eastern'],
            ['route_code' => 'COU', 'route_name' => 'Courier to Collect', 'shipping_zone_id' => 'Z015', 'customer_zone' => 'Self Collection'],
            ['route_code' => 'CTC', 'route_name' => 'Client to Collect', 'shipping_zone_id' => 'Z015', 'customer_zone' => 'Self Collection'],
            ['route_code' => 'INT', 'route_name' => 'Internal Transfer', 'shipping_zone_id' => 'Z014', 'customer_zone' => 'Exports'],
            ['route_code' => 'STC', 'route_name' => 'Staff to Collect', 'shipping_zone_id' => 'Z015', 'customer_zone' => 'Self Collection'],
        ];

        $now = now();

        foreach ($routes as $route) {
            $existing = DB::table('acumatica_routes')->where('route_code', $route['route_code'])->first();

            if ($existing) {
                // Update existing record with non-null fields only (do not overwrite with null)
                $updates = [];
                if ($route['route_name'] !== null && $existing->route_name !== $route['route_name']) {
                    $updates['route_name'] = $route['route_name'];
                }
                if ($route['shipping_zone_id'] !== null && $existing->shipping_zone_id !== $route['shipping_zone_id']) {
                    $updates['shipping_zone_id'] = $route['shipping_zone_id'];
                }
                if ($route['customer_zone'] !== null && $existing->customer_zone !== $route['customer_zone']) {
                    $updates['customer_zone'] = $route['customer_zone'];
                }
                if ($updates !== []) {
                    $updates['updated_at'] = $now;
                    DB::table('acumatica_routes')->where('route_code', $route['route_code'])->update($updates);
                }
            } else {
                // Insert new record
                DB::table('acumatica_routes')->insert(array_merge($route, [
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        $this->command->info('RouteSeeder: ' . count($routes) . ' routes upserted.');
    }
}
