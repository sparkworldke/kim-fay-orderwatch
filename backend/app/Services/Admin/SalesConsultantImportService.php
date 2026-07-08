<?php

namespace App\Services\Admin;

use App\Models\AcumaticaSalesOrder;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesConsultantImportService
{
    private const SOURCE_SALES_ORDERS = 'sales_orders';
    private const SOURCE_ACUMATICA_USERS = 'acumatica_users';

    /** @var list<array{entity:string,field:string}> */
    private const REP_LOOKUP_DEFINITIONS = [
        ['entity' => 'Consultant', 'field' => 'ConsultantID'],
        ['entity' => 'SalesPerson', 'field' => 'SalesPersonID'],
        ['entity' => 'User', 'field' => 'Username'],
        ['entity' => 'User', 'field' => 'UserID'],
    ];

    public function __construct(private readonly AcumaticaClient $client)
    {
    }

    /**
     * @return array{source:string,requested_rep_code:string|null,found:int,created:int,updated:int,skipped:int,errors:list<array<string,string>>,items:list<array<string,mixed>>}
     */
    public function import(string $source, ?string $repCode = null): array
    {
        $repCode = $this->normalizeRepCode($repCode);

        if (! in_array($source, [self::SOURCE_SALES_ORDERS, self::SOURCE_ACUMATICA_USERS], true)) {
            throw new \InvalidArgumentException('Unsupported consultant import source.');
        }

        $records = $source === self::SOURCE_SALES_ORDERS
            ? $this->recordsFromSalesOrders($repCode)
            : $this->recordsFromAcumaticaUsers($repCode);

        $summary = [
            'source' => $source,
            'requested_rep_code' => $repCode,
            'found' => $records->count(),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'items' => [],
        ];

        foreach ($records as $record) {
            try {
                $result = $this->upsertConsultant($record);
                $summary[$result['action']]++;
                $summary['items'][] = $result['item'];
            } catch (Throwable $exception) {
                $summary['skipped']++;
                $summary['errors'][] = [
                    'rep_code' => $record['rep_code'] ?? '',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /** @return Collection<int, array{rep_code:string,name:string|null,email:string|null,source_entity:string}> */
    private function recordsFromSalesOrders(?string $repCode): Collection
    {
        return AcumaticaSalesOrder::query()
            ->whereNotNull('sales_consultant_rep_code')
            ->when($repCode !== null, fn ($query) => $query->where('sales_consultant_rep_code', $repCode))
            ->select([
                DB::raw('UPPER(TRIM(sales_consultant_rep_code)) as rep_code'),
                DB::raw('MAX(sales_consultant_name) as name'),
            ])
            ->groupBy(DB::raw('UPPER(TRIM(sales_consultant_rep_code))'))
            ->get()
            ->map(fn ($row) => [
                'rep_code' => $this->normalizeRepCode($row->rep_code) ?? '',
                'name' => $this->cleanString($row->name) ?: 'Consultant '.$row->rep_code,
                'email' => null,
                'source_entity' => 'SalesOrder',
            ])
            ->filter(fn ($row) => $row['rep_code'] !== '')
            ->values();
    }

    /** @return Collection<int, array{rep_code:string,name:string|null,email:string|null,source_entity:string}> */
    private function recordsFromAcumaticaUsers(?string $repCode): Collection
    {
        if ($repCode !== null) {
            foreach (self::REP_LOOKUP_DEFINITIONS as $definition) {
                try {
                    $raw = $this->client->fetchFirstByField($definition['entity'], $definition['field'], $repCode);
                } catch (RuntimeException) {
                    continue;
                }

                if (is_array($raw)) {
                    return collect([$this->recordFromRaw($definition['entity'], $raw, $repCode)]);
                }
            }

            return collect();
        }

        $records = collect();
        $availableEntities = 0;

        foreach (['Consultant', 'SalesPerson', 'User'] as $entity) {
            try {
                $rawRecords = $this->client->fetchAllEntity($entity);
                $availableEntities++;
            } catch (RuntimeException) {
                continue;
            }

            foreach ($rawRecords as $raw) {
                if (is_array($raw)) {
                    $records->push($this->recordFromRaw($entity, $raw));
                }
            }
        }

        if ($availableEntities === 0) {
            throw new RuntimeException('Acumatica consultant, salesperson, and user entities are unavailable on the configured endpoint.');
        }

        return $records
            ->filter(fn ($record) => $record['rep_code'] !== '')
            ->unique('rep_code')
            ->values();
    }

    /** @param array<string, mixed> $raw */
    private function recordFromRaw(string $entity, array $raw, ?string $fallbackRepCode = null): array
    {
        $repFields = [
            'ConsultantID',
            'SalesPersonID',
            'RepCode',
            'Rep Code',
            'EmployeeID',
        ];

        if ($entity !== 'User' || $fallbackRepCode !== null) {
            $repFields = [...$repFields, 'Username', 'UserID', 'Login'];
        }

        $repCode = $this->firstValue($raw, $repFields) ?? $fallbackRepCode;

        $name = $this->firstValue($raw, [
            'Name',
            'DisplayName',
            'FullName',
            'Description',
            'SalesPersonName',
            'ConsultantName',
        ]);

        $email = $this->firstValue($raw, [
            'Email',
            'EmailAddress',
            'ContactEmail',
        ]);

        $repCode = $this->normalizeRepCode($repCode) ?? '';

        return [
            'rep_code' => $repCode,
            'name' => $this->cleanString($name) ?: 'Consultant '.$repCode,
            'email' => $this->cleanEmail($email),
            'source_entity' => $entity,
        ];
    }

    /**
     * @param array{rep_code:string,name:string|null,email:string|null,source_entity:string} $record
     * @return array{action:string,item:array<string,mixed>}
     */
    private function upsertConsultant(array $record): array
    {
        $repCode = $this->normalizeRepCode($record['rep_code'] ?? null);

        if ($repCode === null) {
            throw new \InvalidArgumentException('Rep Code is required.');
        }

        if (! preg_match('/^[A-Z0-9 ._\-\/]{1,50}$/', $repCode)) {
            throw new \InvalidArgumentException('Rep Code contains unsupported characters.');
        }

        $name = $this->cleanString($record['name'] ?? null) ?: 'Consultant '.$repCode;
        $email = $this->cleanEmail($record['email'] ?? null);
        $generatedEmail = false;

        if ($email === null) {
            $generatedEmail = true;
            $email = 'consultant+'.Str::slug($repCode, '.').'@orderwatch.local';
        }

        $user = User::query()
            ->where('role', 'Sales Consultant')
            ->where('rep_code', $repCode)
            ->first();

        $created = false;

        if (! $user) {
            $created = true;
            $user = new User([
                'role' => 'Sales Consultant',
                'rep_code' => $repCode,
                'password' => bcrypt(Str::random(40)),
                'email_verified_at' => $generatedEmail ? null : now(),
            ]);
        }

        $user->name = $name;

        if (! $generatedEmail || ! $user->exists || str_ends_with((string) $user->email, '@orderwatch.local')) {
            $user->email = strtolower($email);
        }

        if ($created) {
            $user->is_active = ! $generatedEmail;
        }

        $user->save();

        $role = Role::where('name', 'Sales Consultant')->first();
        if ($role) {
            UserRole::updateOrCreate(
                ['user_id' => $user->id],
                ['role_id' => $role->id]
            );
        }

        return [
            'action' => $created ? 'created' : 'updated',
            'item' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'rep_code' => $user->rep_code,
                'is_active' => (bool) $user->is_active,
                'source_entity' => $record['source_entity'],
                'placeholder_email' => $generatedEmail,
            ],
        ];
    }

    /** @param list<string> $keys */
    private function firstValue(array $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = AcumaticaClient::val($raw[$key] ?? null);
            $value = $this->cleanString(is_scalar($value) ? (string) $value : null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeRepCode(?string $value): ?string
    {
        $value = $this->cleanString($value);

        return $value === null ? null : strtoupper($value);
    }

    private function cleanString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function cleanEmail(?string $value): ?string
    {
        $value = $this->cleanString($value);

        return $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : null;
    }
}
