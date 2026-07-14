<?php

namespace Database\Factories;

use App\Models\AcumaticaCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcumaticaCustomer>
 */
class AcumaticaCustomerFactory extends Factory
{
    protected $model = AcumaticaCustomer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyType = fake()->randomElement(['Ltd', 'Limited', 'Supermarket', 'Wholesalers', 'Enterprises', 'Stores']);
        $customerClass = fake()->randomElement(['CHN', 'CHS', 'CHM', 'CHL', 'KP', 'OTH']);

        return [
            'acumatica_id'  => 'CUST' . fake()->unique()->numberBetween(100000, 999999),
            'name'          => fake()->company() . ' ' . $companyType,
            'status'        => fake()->randomElement(['Active', 'Active', 'Active', 'On Hold']),
            'email'         => fake()->optional(0.7)->companyEmail(),
            'phone'         => fake()->optional(0.5)->phoneNumber(),
            'customer_class' => $customerClass,
            'payment_terms'  => fake()->optional()->randomElement(['NET30', 'NET15', 'COD', 'NET45']),
            'tax_zone'       => fake()->optional()->randomElement(['NBI', 'MSA', 'NKU', 'ELD']),
            'shipping_zone_id' => fake()->optional(0.9)->randomElement([
                'Z001', 'Z002', 'Z003', 'Z004', 'Z005', 'Z006',
                'Z007', 'Z008', 'Z009', 'Z010', 'Z011', 'Z012',
                'Z013', 'Z014', 'Z015',
            ]),
            'route_code'   => null, // Set via withRoute() or in seeder
            'synced_at'    => now(),
        ];
    }

    /**
     * Assign a specific route and shipping zone to the customer.
     */
    public function withRoute(string $routeCode, ?string $shippingZoneId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'route_code'       => $routeCode,
            'shipping_zone_id' => $shippingZoneId ?? $attributes['shipping_zone_id'] ?? null,
        ]);
    }
}
