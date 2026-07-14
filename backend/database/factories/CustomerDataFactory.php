<?php

namespace Database\Factories;

use App\Models\CustomerData;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CustomerData>
 */
class CustomerDataFactory extends Factory
{
    protected $model = CustomerData::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $regions = ['Nairobi', 'Central', 'Western', 'Coast', 'Eastern', 'North Eastern', 'Self Collection', 'Export'];

        return [
            'customer_acumatica_id' => 'CUST' . fake()->unique()->numberBetween(100000, 999999),
            'route_code'            => null,
            'shipping_zone_id'      => null,
            'customer_zone'         => fake()->optional()->randomElement(['Westlands', 'CBD', 'Ngong', 'Thika', 'Mombasa Road', 'Mountain']),
            'customer_group'        => fake()->optional()->randomElement(['CHAIN', 'INSTITUTIONS', 'WHOLESALE', 'RETAIL']),
            'tax_registration_id'   => fake()->optional(0.6)->bothify('A########'),
            'currency_id'           => fake()->optional()->randomElement(['KES', 'USD', 'TZS', 'UGX']),
            'price_class_id'        => fake()->optional()->randomElement(['PC001', 'PC002', 'PC003']),
            'price_class_name'      => fake()->optional()->randomElement(['Standard', 'Wholesale', 'Retail']),
            'main_ac_owner'         => fake()->optional()->company(),
            'category'              => fake()->optional()->randomElement(['Chain Supermarket', 'Wholesaler', 'Retailer', 'Institution']),
            'customer_region'       => fake()->optional()->randomElement($regions),
            'sage_code'             => fake()->optional()->bothify('SG####'),
            'business_account_id'   => fake()->optional()->bothify('BA####'),
            'credit_limit'          => fake()->optional()->randomFloat(2, 10000, 5000000),
            'statement_type'        => fake()->optional()->randomElement(['Monthly', 'Weekly', 'Bi-Weekly']),
            'statement_cycle'       => fake()->optional()->randomElement(['CYCLE1', 'CYCLE2', 'CYCLE3']),
            'shipping_rule'         => fake()->optional()->randomElement(['DELIVERY', 'PICKUP', 'COURIER']),
            'delivery'              => fake()->optional()->randomElement(['Standard', 'Express', 'Same Day']),
            'country'               => fake()->optional(0.8)->country(),
            'city'                  => fake()->optional(0.8)->city(),
            'address_line_1'        => fake()->optional()->streetAddress(),
            'address_line_2'        => fake()->optional()->secondaryAddress(),
            'address_line_3'        => null,
            'email'                 => fake()->optional()->companyEmail(),
            'created_by'            => fake()->optional()->userName(),
            'created_on'            => Carbon::instance(fake()->dateTimeBetween('-5 years', 'now')),
            'source'                => 'seeder',
            'synced_at'             => now(),
        ];
    }

    /**
     * Link the customer_data row to a specific customer with route and zone.
     */
    public function forCustomer(string $customerId, ?string $routeCode = null, ?string $shippingZoneId = null): static
    {
        return $this->state(fn (array $attributes) => array_filter([
            'customer_acumatica_id' => $customerId,
            'route_code'            => $routeCode,
            'shipping_zone_id'      => $shippingZoneId,
        ], fn ($v) => $v !== null));
    }
}
