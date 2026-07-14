<?php

namespace Database\Seeders;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaRoute;
use App\Models\AcumaticaShippingZone;
use App\Models\CustomerData;
use App\Models\UserCustomerAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    /**
     * Number of customers to generate via the factory.
     */
    private const CUSTOMER_COUNT = 150;

    /**
     * Percentage of customers that get assigned a rep_code (0-100).
     */
    private const ASSIGN_PERCENTAGE = 70;

    /**
     * Seed customers using Eloquent factories — no Excel/CSV dependency.
     *
     * Each generated customer is linked to a real route and shipping zone
     * (from the RouteSeeder / ShippingZoneSeeder), and optionally assigned
     * to a sales rep via rep_code matching.
     */
    public function run(): void
    {
        $routes = DB::table('acumatica_routes')
            ->orderBy('route_code')
            ->get(['route_code', 'route_name', 'shipping_zone_id', 'customer_zone']);

        if ($routes->isEmpty()) {
            $this->command->warn('CustomerSeeder: No routes found. Run RouteSeeder first.');
            return;
        }

        // Pre-build a lookup map for users by normalized rep_code and employee_number.
        $userByRepCode = $this->buildUserLookup('rep_code');
        $userByEmployeeNumber = $this->buildUserLookup('employee_number');

        $now = now();
        $stats = ['customers' => 0, 'data' => 0, 'assignments' => 0, 'unmatched' => 0];

        for ($i = 0; $i < self::CUSTOMER_COUNT; $i++) {
            /** @var \App\Models\AcumaticaRoute $route */
            $route = $routes->random();

            // --- Create the AcumaticaCustomer via factory ---
            $customer = AcumaticaCustomer::factory()->withRoute(
                $route->route_code,
                $route->shipping_zone_id,
            )->create([
                'synced_at' => $now,
            ]);
            $stats['customers']++;

            // --- Create the CustomerData row via factory ---
            CustomerData::factory()->forCustomer(
                $customer->acumatica_id,
                $route->route_code,
                $route->shipping_zone_id,
            )->create([
                'customer_zone' => $route->customer_zone,
                'synced_at'     => $now,
            ]);
            $stats['data']++;

            // --- Attempt rep_code assignment (deterministic subset) ---
            if (($i * 100 / self::CUSTOMER_COUNT) >= self::ASSIGN_PERCENTAGE) {
                continue;
            }

            // Pick a random rep_code from the user lookup pool.
            $repKeys = array_keys($userByRepCode);
            if ($repKeys === []) {
                continue;
            }

            $repCode = $repKeys[array_rand($repKeys)];

            $matchedUser = $userByRepCode[$repCode]
                ?? ($userByEmployeeNumber[$repCode] ?? null);

            if ($matchedUser !== null) {
                UserCustomerAssignment::query()->updateOrCreate(
                    [
                        'user_id'                 => $matchedUser['id'],
                        'customer_acumatica_id'   => $customer->acumatica_id,
                    ],
                    [
                        'assignment_type' => 'primary',
                        'source'          => 'seeder',
                        'confidence'      => 100,
                    ],
                );
                $stats['assignments']++;
            } else {
                $stats['unmatched']++;
            }
        }

        $this->command->info(sprintf(
            'CustomerSeeder (factory): %d customers, %d data rows, %d assignments created (%d unmatched rep codes).',
            $stats['customers'],
            $stats['data'],
            $stats['assignments'],
            $stats['unmatched'],
        ));
    }

    /**
     * Build a lookup map of users keyed by the normalized value of a column.
     *
     * @return array<string, array{id: int, name: string}>
     */
    private function buildUserLookup(string $column): array
    {
        $map = [];
        $users = DB::table('users')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->get(['id', 'name', $column]);

        foreach ($users as $user) {
            $normalized = $this->normalizeCode($user->{$column});
            if ($normalized !== null && ! isset($map[$normalized])) {
                $map[$normalized] = ['id' => $user->id, 'name' => $user->name];
            }
        }

        return $map;
    }

    private function normalizeCode(mixed $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = trim((string) $code);

        return $code === '' ? null : strtoupper($code);
    }
}
