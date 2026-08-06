<?php

namespace Database\Seeders;

use App\Models\AcumaticaCustomer;
use App\Models\CustomerContact;
use App\Models\CustomerData;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KpCustomerPortfolio202608Seeder extends Seeder
{
    private const SOURCE_FILE = 'data/kp-customers-20260805.csv';

    /** @var array<string, string> */
    private const FIELD_MAP = [
        'Route Code' => 'route_code',
        'Zone ID' => 'shipping_zone_id',
        'Customer Zone' => 'customer_zone',
        'Customer Group' => 'customer_group',
        'Tax Registration ID' => 'tax_registration_id',
        'Currency ID' => 'currency_id',
        'Price Class ID' => 'price_class_id',
        'Price Class Name' => 'price_class_name',
        'Main A/CC Owner' => 'main_ac_owner',
        'Rep Code' => 'rep_code',
        'Sales Rep' => 'sales_rep',
        'Category' => 'category',
        'Customer Region' => 'customer_region',
        'Sage Code' => 'sage_code',
        'Business Account ID' => 'business_account_id',
        'Statement Type' => 'statement_type',
        'Statement cycle' => 'statement_cycle',
        'Shipping Rule' => 'shipping_rule',
        'Delivery' => 'delivery',
        'Country' => 'country',
        'City' => 'city',
        'Address Line 1' => 'address_line_1',
        'Address Line 2' => 'address_line_2',
        'Address Line 3' => 'address_line_3',
        'Email' => 'email',
    ];

    public function run(): void
    {
        $path = __DIR__.'/'.self::SOURCE_FILE;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read KP customer import file: {$path}");
        }

        try {
            $headers = fgetcsv($handle, escape: '');
            if ($headers === false) {
                throw new RuntimeException('The KP customer import file is empty.');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $this->assertRequiredHeaders($headers);

            $imported = 0;
            $contactsCreated = 0;
            $contactsWithoutPrimary = [];
            $assignmentsCreated = 0;
            $unresolvedRepCodes = [];

            DB::transaction(function () use ($handle, $headers, &$imported, &$contactsCreated, &$contactsWithoutPrimary, &$assignmentsCreated, &$unresolvedRepCodes): void {
                while (($values = fgetcsv($handle, escape: '')) !== false) {
                    if ($values === [null] || $values === []) {
                        continue;
                    }

                    if (count($values) !== count($headers)) {
                        throw new RuntimeException(sprintf(
                            'Malformed KP customer CSV row %d: expected %d columns, found %d.',
                            $imported + 2,
                            count($headers),
                            count($values),
                        ));
                    }

                    /** @var array<string, string|null> $row */
                    $row = array_combine($headers, $values);
                    $customerId = $this->clean($row['Customer ID'] ?? null);
                    if ($customerId === null) {
                        throw new RuntimeException(sprintf('Missing Customer ID on CSV row %d.', $imported + 2));
                    }

                    $attributes = [];
                    foreach (self::FIELD_MAP as $csvColumn => $modelColumn) {
                        $attributes[$modelColumn] = $this->clean($row[$csvColumn] ?? null);
                    }

                    $attributes['credit_limit'] = $this->decimal($row['Credit Limit'] ?? null, $customerId);
                    $attributes['created_by'] = self::class;
                    $attributes['created_on'] = now();
                    $attributes['source'] = 'excel_upload';
                    $attributes['synced_at'] = now();

                    AcumaticaCustomer::query()->updateOrCreate(
                        ['acumatica_id' => $customerId],
                        [
                            'name' => $this->clean($row['Customer Name'] ?? null) ?? $customerId,
                            'customer_class' => $this->clean($row['Customer Class'] ?? null),
                            'payment_terms' => $this->clean($row['Terms'] ?? null),
                            'status' => $this->clean($row['Customer Status'] ?? null),
                            'parent_acumatica_id' => $this->clean($row['Parent Code'] ?? null),
                            'route_code' => $attributes['route_code'],
                            'shipping_zone_id' => $attributes['shipping_zone_id'],
                            'email' => $attributes['email'],
                            'synced_at' => now(),
                        ],
                    );

                    CustomerData::query()->updateOrCreate(
                        ['customer_acumatica_id' => $customerId],
                        $attributes,
                    );

                    if ($attributes['customer_group'] === 'Kim-Fay Professional' && $attributes['rep_code'] !== null) {
                        $repCode = strtoupper($attributes['rep_code']);
                        $owner = User::query()
                            ->where('is_active', true)
                            ->whereRaw('UPPER(TRIM(rep_code)) = ?', [$repCode])
                            ->first();

                        if ($owner) {
                            $assignment = UserCustomerAssignment::query()->firstOrCreate(
                                [
                                    'user_id' => $owner->id,
                                    'customer_acumatica_id' => $customerId,
                                    'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
                                ],
                                [
                                    'source' => 'kp_customers_20260805',
                                    'notes' => 'Servicing portfolio imported from KP-Customers 20260805.',
                                    'confidence' => 100,
                                    'priority' => 20,
                                    'is_manual_override' => false,
                                ],
                            );
                            $assignmentsCreated += $assignment->wasRecentlyCreated ? 1 : 0;
                        } else {
                            $unresolvedRepCodes[$repCode] = true;
                        }
                    }

                    $contacts = CustomerContact::query()->where('customer_acumatica_id', $customerId);
                    if (! $contacts->exists()) {
                        CustomerContact::query()->create([
                            'customer_acumatica_id' => $customerId,
                            'designation_key' => CustomerContact::DESIGNATION_CUSTOM,
                            'designation_label' => CustomerContact::DESIGNATIONS[CustomerContact::DESIGNATION_CUSTOM],
                            'first_name' => $this->clean($row['Customer Name'] ?? null) ?? $customerId,
                            'last_name' => '',
                            'email' => $this->clean($row['Email'] ?? null),
                            'is_primary' => true,
                            'is_active' => true,
                            'notes' => 'Default company contact created by the KP customer portfolio import.',
                        ]);
                        $contactsCreated++;
                    } elseif (! (clone $contacts)->where('is_primary', true)->exists()) {
                        $contactsWithoutPrimary[] = $customerId;
                    }

                    $imported++;
                }
            });
        } finally {
            fclose($handle);
        }

        $this->command?->info("KP customer portfolio: {$imported} rows imported; {$contactsCreated} default contacts and {$assignmentsCreated} servicing assignments created.");

        if ($contactsWithoutPrimary !== []) {
            $this->command?->warn(sprintf(
                '%d customers already had contacts but no primary contact; existing contact records were left untouched: %s',
                count($contactsWithoutPrimary),
                implode(', ', array_slice($contactsWithoutPrimary, 0, 20)),
            ));
        }

        if ($unresolvedRepCodes !== []) {
            $this->command?->warn('KP customer rep codes without an active user profile: '.implode(', ', array_keys($unresolvedRepCodes)));
        }
    }

    /** @param list<string> $headers */
    private function assertRequiredHeaders(array $headers): void
    {
        $required = ['Customer ID', 'Customer Name', 'Customer Class', 'Terms', 'Customer Status', 'Parent Code', 'Credit Limit', ...array_keys(self::FIELD_MAP)];
        $missing = array_values(array_diff(array_unique($required), $headers));

        if ($missing !== []) {
            throw new RuntimeException('KP customer import is missing required columns: '.implode(', ', $missing));
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function decimal(?string $value, string $customerId): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([',', ' '], '', $value);
        if (! is_numeric($normalized)) {
            throw new RuntimeException("Invalid Credit Limit '{$value}' for customer {$customerId}.");
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
