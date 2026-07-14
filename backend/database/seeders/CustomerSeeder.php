<?php

namespace Database\Seeders;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaRoute;
use App\Models\AcumaticaShippingZone;
use App\Models\CustomerData;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CustomerSeeder extends Seeder
{
    /**
     * Path to the Excel export relative to the project root.
     */
    private const EXCEL_RELATIVE_PATH = 'Customers 20260713.xlsx';

    public function run(): void
    {
        $path = base_path('../' . self::EXCEL_RELATIVE_PATH);

        if (! file_exists($path)) {
            // Also try the workspace root directly.
            $altPath = base_path(self::EXCEL_RELATIVE_PATH);
            if (! file_exists($altPath)) {
                $this->command->error("CustomerSeeder: Excel file not found at {$path}");
                return;
            }
            $path = $altPath;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, true);
        if ($rawRows === []) {
            $this->command->error('CustomerSeeder: Excel file is empty.');
            return;
        }

        $headerRow = array_shift($rawRows);
        $headers = [];
        foreach ($headerRow as $column => $label) {
            $key = $this->headerKey($label);
            if ($key !== null) {
                $headers[$column] = $key;
            }
        }

        $now = now();
        $stats = ['customers' => 0, 'data' => 0, 'assignments' => 0, 'unmatched' => 0];
        $processedIds = [];

        // Pre-build a lookup map for users by normalized rep_code and employee_number.
        $userByRepCode = $this->buildUserLookup('rep_code');
        $userByEmployeeNumber = $this->buildUserLookup('employee_number');

        foreach ($rawRows as $rawRow) {
            $mapped = [];
            foreach ($headers as $column => $key) {
                $mapped[$key] = trim((string) ($rawRow[$column] ?? ''));
            }

            // Skip empty rows.
            $hasData = false;
            foreach ($mapped as $value) {
                if ($value !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (! $hasData) {
                continue;
            }

            $customerId = $this->normalizeCode($mapped['customer_id'] ?? null);
            if ($customerId === null) {
                continue;
            }

            $processedIds[] = $customerId;

            // --- Resolve shipping zone ---
            $shippingZoneId = $this->normalizeCode($mapped['zone_id'] ?? null);
            $customerZone = $mapped['customer_zone'] ?? null;
            if ($customerZone === '') {
                $customerZone = null;
            }
            if ($shippingZoneId !== null) {
                AcumaticaShippingZone::query()->firstOrCreate(
                    ['acumatica_id' => $shippingZoneId],
                    array_filter([
                        'description' => $customerZone,
                        'name' => $customerZone,
                        'synced_at' => $now,
                    ], fn ($v) => $v !== null && $v !== ''),
                );
            } else {
                $shippingZoneId = null;
            }

            // --- Resolve route ---
            $routeCode = $this->normalizeCode($mapped['route_code'] ?? null);
            $routeName = $mapped['route_name'] ?? null;
            if ($routeName === '') {
                $routeName = null;
            }
            if ($routeCode !== null) {
                AcumaticaRoute::query()->updateOrCreate(
                    ['route_code' => $routeCode],
                    array_filter([
                        'route_name' => $routeName,
                        'shipping_zone_id' => $shippingZoneId,
                        'customer_zone' => $customerZone,
                        'synced_at' => $now,
                    ], fn ($v) => $v !== null && $v !== ''),
                );
            } else {
                $routeCode = null;
            }

            // --- Upsert acumatica_customers ---
            $customerName = $mapped['customer_name'] ?? $customerId;
            $customerStatus = $mapped['customer_status'] ?? null;
            if ($customerStatus === '') {
                $customerStatus = null;
            }
            $customerClass = $mapped['customer_class'] ?? null;
            if ($customerClass === '') {
                $customerClass = null;
            }
            $email = $mapped['email'] ?? null;
            if ($email === '') {
                $email = null;
            }

            DB::table('acumatica_customers')->updateOrInsert(
                ['acumatica_id' => $customerId],
                array_filter([
                    'name' => $customerName,
                    'status' => $customerStatus,
                    'customer_class' => $customerClass,
                    'email' => $email,
                    'route_code' => $routeCode,
                    'shipping_zone_id' => $shippingZoneId,
                    'synced_at' => $now,
                    'updated_at' => $now,
                ], fn ($v) => $v !== null && $v !== ''),
            );
            $stats['customers']++;

            // --- Upsert customer_data ---
            $creditLimit = isset($mapped['credit_limit']) && $mapped['credit_limit'] !== ''
                ? (float) preg_replace('/[^0-9.\-]/', '', $mapped['credit_limit'])
                : null;

            $createdOn = null;
            if (isset($mapped['created_on']) && $mapped['created_on'] !== '') {
                try {
                    $createdOn = Carbon::parse($mapped['created_on']);
                } catch (\Throwable) {
                    $createdOn = null;
                }
            }

            $dataPayload = array_filter([
                'route_code' => $routeCode,
                'shipping_zone_id' => $shippingZoneId,
                'customer_zone' => $customerZone,
                'customer_group' => $this->nullable($mapped, 'customer_group'),
                'tax_registration_id' => $this->nullable($mapped, 'tax_registration_id'),
                'currency_id' => $this->nullable($mapped, 'currency_id'),
                'price_class_id' => $this->nullable($mapped, 'price_class_id'),
                'price_class_name' => $this->nullable($mapped, 'price_class_name'),
                'main_ac_owner' => $this->nullable($mapped, 'main_ac_owner'),
                'category' => $this->nullable($mapped, 'category'),
                'customer_region' => $this->nullable($mapped, 'customer_region'),
                'sage_code' => $this->nullable($mapped, 'sage_code'),
                'business_account_id' => $this->nullable($mapped, 'business_account_id'),
                'credit_limit' => $creditLimit,
                'statement_type' => $this->nullable($mapped, 'statement_type'),
                'statement_cycle' => $this->nullable($mapped, 'statement_cycle'),
                'shipping_rule' => $this->nullable($mapped, 'shipping_rule'),
                'delivery' => $this->nullable($mapped, 'delivery'),
                'country' => $this->nullable($mapped, 'country'),
                'city' => $this->nullable($mapped, 'city'),
                'address_line_1' => $this->nullable($mapped, 'address_line_1'),
                'address_line_2' => $this->nullable($mapped, 'address_line_2'),
                'address_line_3' => $this->nullable($mapped, 'address_line_3'),
                'email' => $email,
                'created_by' => $this->nullable($mapped, 'created_by'),
                'created_on' => $createdOn,
                'source' => 'seeder',
                'synced_at' => $now,
                'updated_at' => $now,
            ], fn ($v) => $v !== null && $v !== '');

            DB::table('customer_data')->updateOrInsert(
                ['customer_acumatica_id' => $customerId],
                $dataPayload,
            );
            $stats['data']++;

            // --- Match customer to user via rep_code (default) then employee_number (backup) ---
            $repCode = $this->normalizeCode($mapped['rep_code'] ?? null);
            $matchedUser = null;

            if ($repCode !== null) {
                // Try rep_code match first.
                if (isset($userByRepCode[$repCode])) {
                    $matchedUser = $userByRepCode[$repCode];
                }
                // Fall back to employee_number match.
                if ($matchedUser === null && isset($userByEmployeeNumber[$repCode])) {
                    $matchedUser = $userByEmployeeNumber[$repCode];
                }
            }

            if ($matchedUser !== null) {
                UserCustomerAssignment::query()->updateOrCreate(
                    [
                        'user_id' => $matchedUser['id'],
                        'customer_acumatica_id' => $customerId,
                    ],
                    [
                        'assignment_type' => 'primary',
                        'source' => 'seeder',
                        'confidence' => 100,
                    ],
                );
                $stats['assignments']++;
            } elseif ($repCode !== null) {
                $stats['unmatched']++;
            }
        }

        $this->command->info(sprintf(
            'CustomerSeeder: %d customers, %d data rows, %d assignments created (%d unmatched rep codes).',
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

    private function nullable(array $mapped, string $key): ?string
    {
        $value = $mapped[$key] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    private function normalizeCode(mixed $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = trim((string) $code);

        return $code === '' ? null : strtoupper($code);
    }

    private function headerKey(mixed $label): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $label));

        return match ($key) {
            'repcode', 'employeecode', 'salesrep', 'salesperson' => 'rep_code',
            'customerid', 'customer', 'customercode', 'acumaticacustomerid' => 'customer_id',
            'customername', 'name' => 'customer_name',
            'customerclass' => 'customer_class',
            'customergroup' => 'customer_group',
            'taxregistrationid', 'taxregid', 'taxid', 'kpıno', 'kpinumber' => 'tax_registration_id',
            'createdon' => 'created_on',
            'country' => 'country',
            'city' => 'city',
            'createdby' => 'created_by',
            'currencyid', 'currency' => 'currency_id',
            'customerstatus', 'status' => 'customer_status',
            'sagecode' => 'sage_code',
            'category' => 'category',
            'customerregion', 'region' => 'customer_region',
            'priceclassid' => 'price_class_id',
            'priceclassname' => 'price_class_name',
            'mainaccowner', 'mainacc' => 'main_ac_owner',
            'routecode', 'route' => 'route_code',
            'routename' => 'route_name',
            'creditlimit' => 'credit_limit',
            'zoneid', 'zone' => 'zone_id',
            'customerzone' => 'customer_zone',
            'addressline1', 'address1' => 'address_line_1',
            'addressline2', 'address2' => 'address_line_2',
            'addressline3', 'address3' => 'address_line_3',
            'shippingrule' => 'shipping_rule',
            'email' => 'email',
            'statementtype' => 'statement_type',
            'businessaccountid' => 'business_account_id',
            'delivery' => 'delivery',
            'statementcycle' => 'statement_cycle',
            default => null,
        };
    }
}
