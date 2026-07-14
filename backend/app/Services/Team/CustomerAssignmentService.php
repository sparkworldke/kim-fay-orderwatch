<?php

namespace App\Services\Team;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaRoute;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaShippingZone;
use App\Models\CustomerAssignmentBatch;
use App\Models\CustomerAssignmentBatchRow;
use App\Models\CustomerData;
use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserCustomerAssignment;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class CustomerAssignmentService
{
    private const CUSTOMER_REP_FIELDS = [
        'SalesPersonID',
        'SalespersonID',
        'DefaultSalespersonID',
        'DefaultSalesPersonID',
        'SalesRepID',
        'Salesperson',
        'SalesPerson',
    ];

    public function __construct(private readonly AcumaticaClient $client) {}

    /**
     * Legacy wrapper used by older buttons/tests. New UI uses preview + apply.
     *
     * @return array{added: int, total: int, customer_ids: list<string>}
     */
    public function backfillFromSalesOrders(User $user, ?int $assignedBy = null): array
    {
        $batch = $this->previewFromSalesOrders($user, $assignedBy);
        $this->applyBatch($batch, $assignedBy);
        $batch->refresh();

        return [
            'added' => (int) ($batch->stats_json['created'] ?? 0),
            'total' => (int) ($batch->stats_json['valid'] ?? 0),
            'customer_ids' => $batch->rows()
                ->where('status', 'valid')
                ->pluck('customer_acumatica_id')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /** @param  list<string>  $customerIds */
    public function syncAssignments(User $user, array $customerIds, ?int $assignedBy = null): void
    {
        $customerIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $customerIds,
        ))));

        UserCustomerAssignment::query()
            ->where('user_id', $user->id)
            ->whereNotIn('customer_acumatica_id', $customerIds)
            ->delete();

        foreach ($customerIds as $customerId) {
            UserCustomerAssignment::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'customer_acumatica_id' => $customerId,
                ],
                [
                    'assignment_type' => 'primary',
                    'assigned_by' => $assignedBy,
                    'source' => 'manual',
                ],
            );
        }
    }

    public function sourceStatus(): array
    {
        $endpoint = [
            'available' => false,
            'field' => null,
            'message' => 'Customer endpoint has no rep field; use SO import or upload.',
        ];

        try {
            $sample = $this->client->fetchCustomers(0, 5);
            $field = $this->detectCustomerRepField($sample);
            if ($field !== null) {
                $endpoint = [
                    'available' => true,
                    'field' => $field,
                    'message' => "Customer endpoint rep field detected: {$field}.",
                ];
            }
        } catch (Throwable $e) {
            $endpoint['message'] = 'Customer endpoint could not be checked: '.$e->getMessage();
        }

        return [
            'sales_orders' => [
                'available' => true,
                'message' => 'Uses imported SO sales consultant codes and linked consultant users.',
            ],
            'customer_endpoint' => $endpoint,
            'upload' => [
                'available' => true,
                'message' => 'Upload CSV, XLS, or XLSX with rep_code and customer_id.',
            ],
        ];
    }

    public function previewFromSalesOrders(User $targetUser, ?int $actorId = null): CustomerAssignmentBatch
    {
        $rows = $this->rowsFromSalesOrders($targetUser);

        return $this->createBatch('so_match', $targetUser, $actorId, null, $rows);
    }

    public function previewFromCustomerEndpoint(User $targetUser, ?int $actorId = null, ?string $requestedField = null): CustomerAssignmentBatch
    {
        $repCodes = $this->repCodesForUser($targetUser);
        if ($repCodes->isEmpty()) {
            return $this->createBatch('customer_endpoint', $targetUser, $actorId, null, [[
                'row_no' => 0,
                'rep_code' => null,
                'customer_acumatica_id' => null,
                'customer_name' => null,
                'resolved_user_id' => $targetUser->id,
                'action' => 'error',
                'status' => 'error',
                'message' => 'Target user has no rep code or Acumatica rep mapping.',
                'details_json' => null,
            ]]);
        }

        $customers = $this->client->fetchAllCustomers();
        $field = $requestedField ?: $this->detectCustomerRepField($customers);
        if ($field === null) {
            return $this->createBatch('customer_endpoint', $targetUser, $actorId, null, [[
                'row_no' => 0,
                'rep_code' => null,
                'customer_acumatica_id' => null,
                'customer_name' => null,
                'resolved_user_id' => $targetUser->id,
                'action' => 'error',
                'status' => 'error',
                'message' => 'Customer endpoint has no rep field; use SO import or upload.',
                'details_json' => null,
            ]]);
        }

        $rows = [];
        foreach ($customers as $index => $raw) {
            $repCode = $this->normalizeCode($this->valueFromAcumaticaField($raw[$field] ?? null));
            if ($repCode === null || ! $repCodes->contains($repCode)) {
                continue;
            }

            $customerId = $this->normalizeCustomerId($this->valueFromAcumaticaField($raw['CustomerID'] ?? null));
            $rows[] = $this->buildRow(
                rowNo: $index + 1,
                repCode: $repCode,
                customerId: $customerId,
                user: $targetUser,
                source: 'customer_endpoint',
                details: ['rep_field' => $field],
            );
        }

        return $this->createBatch('customer_endpoint', $targetUser, $actorId, null, $rows);
    }

    public function previewUpload(UploadedFile $file, ?int $actorId = null): CustomerAssignmentBatch
    {
        $rows = [];
        foreach ($this->readUploadRows($file) as $row) {
            $repCode = $this->normalizeCode($row['rep_code'] ?? null);
            $customerId = $this->normalizeCustomerId($row['customer_id'] ?? null);
            $resolution = $repCode ? $this->resolveUserByRepCode($repCode) : null;
            $user = $resolution['user'] ?? null;

            $rows[] = $this->buildRow(
                rowNo: (int) $row['_row_no'],
                repCode: $repCode,
                customerId: $customerId,
                user: $user,
                source: 'upload',
                resolution: $resolution,
                details: ['raw' => $row],
            );
        }

        return $this->createBatch('upload', null, $actorId, $file->getClientOriginalName(), $rows);
    }

    public function applyBatch(CustomerAssignmentBatch $batch, ?int $actorId = null): CustomerAssignmentBatch
    {
        if ($batch->status === 'applied') {
            return $batch->fresh(['rows']);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($batch, $actorId, &$created, &$updated): void {
            $rows = $batch->rows()->where('status', 'valid')->get();

            foreach ($rows as $row) {
                if (! $row->resolved_user_id || ! $row->customer_acumatica_id) {
                    continue;
                }

                // The raw uploaded row is carried inside details_json['raw']
                // and holds the extended Excel fields (route, zone, credit, etc.).
                $details = $row->details_json ?? [];
                $rawFields = $details['raw'] ?? [];

                // Persist the extended customer attributes (route, shipping zone,
                // and all remaining Excel columns) into their respective tables.
                $this->upsertCustomerData($row->customer_acumatica_id, $rawFields);

                // Mirror the route code and shipping zone onto the existing
                // Acumatica customer master row.
                $customerAttributes = array_filter([
                    'route_code' => $this->normalizeCode($rawFields['route_code'] ?? null),
                    'shipping_zone_id' => $this->normalizeCode($rawFields['zone_id'] ?? null),
                    'customer_class' => $this->normalizeCode($rawFields['customer_class'] ?? null),
                ], fn ($value) => $value !== null);

                $this->updateExistingCustomer($row->customer_acumatica_id, $customerAttributes);

                $assignment = UserCustomerAssignment::query()->updateOrCreate(
                    [
                        'user_id' => $row->resolved_user_id,
                        'customer_acumatica_id' => $row->customer_acumatica_id,
                    ],
                    [
                        'assignment_type' => 'primary',
                        'assigned_by' => $actorId,
                        'notes' => $this->notesForSource($batch->source),
                        'source' => $batch->source,
                        'source_batch_id' => $batch->uuid,
                        'last_so_date' => $details['last_so_date'] ?? null,
                        'so_order_count' => $details['so_order_count'] ?? null,
                        'confidence' => $details['confidence'] ?? null,
                    ],
                );

                $assignment->wasRecentlyCreated ? $created++ : $updated++;
            }

            $stats = $batch->stats_json ?? [];
            $stats['created'] = $created;
            $stats['updated'] = $updated;
            $stats['applied'] = $created + $updated;

            $batch->forceFill([
                'status' => 'applied',
                'stats_json' => $stats,
                'applied_at' => now(),
            ])->save();
        });

        return $batch->fresh(['rows']);
    }

    public function presentBatch(CustomerAssignmentBatch $batch): array
    {
        $batch->loadMissing('rows');

        return [
            ...$batch->toArray(),
            'rows' => $batch->rows->values(),
        ];
    }

    private function createBatch(string $source, ?User $targetUser, ?int $actorId, ?string $filename, array $rows): CustomerAssignmentBatch
    {
        return DB::transaction(function () use ($source, $targetUser, $actorId, $filename, $rows): CustomerAssignmentBatch {
            $batch = CustomerAssignmentBatch::create([
                'uuid' => (string) Str::uuid(),
                'source' => $source,
                'mode' => 'add_only',
                'status' => 'dry_run',
                'initiated_by' => $actorId,
                'target_user_id' => $targetUser?->id,
                'filename' => $filename,
                'stats_json' => $this->statsForRows($rows),
            ]);

            foreach ($rows as $row) {
                $batch->rows()->create([
                    'row_no' => $row['row_no'] ?? 0,
                    'rep_code' => $row['rep_code'] ?? null,
                    'customer_acumatica_id' => $row['customer_acumatica_id'] ?? null,
                    'customer_name' => $row['customer_name'] ?? null,
                    'resolved_user_id' => $row['resolved_user_id'] ?? null,
                    'action' => $row['action'] ?? 'error',
                    'status' => $row['status'] ?? 'error',
                    'source' => $source,
                    'message' => $row['message'] ?? null,
                    'details_json' => $row['details_json'] ?? null,
                ]);
            }

            return $batch->fresh(['rows']);
        });
    }

    private function rowsFromSalesOrders(User $targetUser): array
    {
        $repCodes = $this->repCodesForUser($targetUser);
        $query = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->whereNotNull('customer_acumatica_id')
            ->where(function ($scoped) use ($targetUser, $repCodes) {
                $scoped->where('consultant_user_id', $targetUser->id);
                if ($repCodes->isNotEmpty()) {
                    $scoped->orWhereIn(DB::raw('UPPER(TRIM(sales_consultant_rep_code))'), $repCodes->all());
                }
            });

        return $query
            ->selectRaw('customer_acumatica_id, MAX(customer_name) as customer_name, MAX(UPPER(TRIM(sales_consultant_rep_code))) as rep_code, COUNT(*) as so_order_count, MAX(order_date) as last_so_date')
            ->groupBy('customer_acumatica_id')
            ->orderBy('customer_acumatica_id')
            ->get()
            ->values()
            ->map(fn ($row, int $index): array => $this->buildRow(
                rowNo: $index + 1,
                repCode: $this->normalizeCode($row->rep_code),
                customerId: $this->normalizeCustomerId($row->customer_acumatica_id),
                user: $targetUser,
                source: 'so_match',
                fallbackName: $row->customer_name,
                details: [
                    'so_order_count' => (int) $row->so_order_count,
                    'last_so_date' => $row->last_so_date,
                    'confidence' => 95,
                ],
            ))
            ->all();
    }

    private function buildRow(int $rowNo, ?string $repCode, ?string $customerId, ?User $user, string $source, ?string $fallbackName = null, array $details = [], ?array $resolution = null): array
    {
        $errors = [];

        if ($repCode === null) {
            $errors[] = 'Rep code is required.';
        }

        if ($customerId === null || $customerId === '') {
            $errors[] = 'Customer ID is required.';
        }

        if ($repCode !== null && $user === null) {
            $errors[] = $this->repCodeResolutionError($resolution, $repCode);
        }

        $customer = $customerId
            ? AcumaticaCustomer::query()->where('acumatica_id', $customerId)->first()
            : null;

        if ($customerId !== null && $customer === null) {
            $errors[] = "Customer ID {$customerId} was not found in the Acumatica customer master.";
        }

        $exists = $user && $customerId
            ? UserCustomerAssignment::query()
                ->where('user_id', $user->id)
                ->where('customer_acumatica_id', $customerId)
                ->exists()
            : false;

        $message = match (true) {
            $errors !== [] => implode(' ', $errors),
            $exists => 'Existing assignment will be updated.',
            default => 'Assignment will be created.',
        };

        return [
            'row_no' => $rowNo,
            'rep_code' => $repCode,
            'customer_acumatica_id' => $customerId,
            'customer_name' => $customer?->name ?? $fallbackName,
            'resolved_user_id' => $user?->id,
            'action' => $errors === [] ? ($exists ? 'update' : 'create') : 'error',
            'status' => $errors === [] ? 'valid' : 'error',
            'message' => $message,
            'details_json' => $details,
        ];
    }

    /** @return Collection<int, string> */
    private function repCodesForUser(User $user): Collection
    {
        $codes = collect();

        foreach ([$user->rep_code, $user->employee_number] as $code) {
            $normalized = $this->normalizeCode($code);
            if ($normalized !== null) {
                $codes->push($normalized);
            }
        }

        $mapped = $user->acumaticaRepMappings()
            ->whereNotNull('acumatica_rep_code')
            ->pluck('acumatica_rep_code')
            ->map(fn ($code) => $this->normalizeCode($code))
            ->filter();

        return $codes->merge($mapped)->filter()->unique()->values();
    }

    /**
     * Resolve an uploaded rep code against active users.
     *
     * Direct rep codes (users.rep_code) take priority, followed by the
     * Acumatica rep-code mappings (user_acumatica_rep_mappings). Only active
     * users are eligible; codes that match only inactive users are reported as
     * "inactive" so the operator can distinguish them from genuinely unknown codes.
     *
     * @return array{user: User|null, reason: string}|null
     */
    private function resolveUserByRepCode(string $repCode): ?array
    {
        $directUserIds = User::query()
            ->whereRaw('UPPER(TRIM(rep_code)) = ?', [$repCode])
            ->pluck('id');

        if ($directUserIds->isNotEmpty()) {
            $activeDirect = User::query()
                ->whereIn('id', $directUserIds)
                ->where('is_active', true)
                ->get();

            if ($activeDirect->count() === 1) {
                return ['user' => $activeDirect->first(), 'reason' => 'resolved'];
            }

            return ['user' => null, 'reason' => $activeDirect->count() > 1 ? 'ambiguous' : 'inactive'];
        }

        $mappedUserIds = UserAcumaticaRepMapping::query()
            ->whereRaw('UPPER(TRIM(acumatica_rep_code)) = ?', [$repCode])
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($mappedUserIds->isEmpty()) {
            return ['user' => null, 'reason' => 'unresolved'];
        }

        $activeMapped = User::query()
            ->whereIn('id', $mappedUserIds)
            ->where('is_active', true)
            ->get();

        if ($activeMapped->count() === 1) {
            return ['user' => $activeMapped->first(), 'reason' => 'resolved'];
        }

        if ($activeMapped->count() > 1) {
            return ['user' => null, 'reason' => 'ambiguous'];
        }

        return ['user' => null, 'reason' => 'inactive'];
    }

    /**
     * Build the precise error message for an unresolved rep code.
     */
    private function repCodeResolutionError(?array $resolution, string $repCode): string
    {
        $reason = $resolution['reason'] ?? 'unresolved';

        return match ($reason) {
            'inactive' => "Rep code {$repCode} matched only inactive users.",
            'ambiguous' => "Rep code {$repCode} matched multiple users.",
            default => "Rep code {$repCode} did not resolve to one active user.",
        };
    }

    /**
     * Update routing attributes on an existing Acumatica customer master row.
     * Preview validation guarantees the customer exists before apply.
     *
     * @param  array<string, mixed>  $attributes  Extra attributes keyed by the
     *     acumatica_customers fillable names (route_code, shipping_zone_id, ...).
     */
    private function updateExistingCustomer(string $acumaticaId, array $attributes = []): void
    {
        $customer = AcumaticaCustomer::query()->where('acumatica_id', $acumaticaId)->first();
        if (! $customer) {
            return;
        }

        $updates = [];
        foreach (['shipping_zone_id', 'route_code', 'customer_class'] as $field) {
            if (isset($attributes[$field]) && $customer->getAttribute($field) !== $attributes[$field]) {
                $updates[$field] = $attributes[$field];
            }

        }

        if ($updates !== []) {
            $customer->forceFill($updates)->save();
        }
    }

    /**
     * Ensure a route row exists in the acumatica_routes lookup table. Each
     * route belongs to a shipping zone (Zone ID / Customer Zone), so the zone
     * is resolved first and its id is stored on the route.
     *
     * @return string|null The normalized route code, or null when empty.
     */
    private function ensureRouteExists(?string $routeCode, ?string $routeName = null, ?string $shippingZoneId = null, ?string $customerZone = null): ?string
    {
        $code = $this->normalizeCode($routeCode);
        if ($code === null) {
            return null;
        }

        $route = AcumaticaRoute::query()->firstOrCreate(
            ['route_code' => $code],
            array_filter([
                'route_name' => $routeName,
                'shipping_zone_id' => $shippingZoneId,
                'customer_zone' => $customerZone,
                'synced_at' => now(),
            ], fn ($value) => $value !== null && $value !== ''),
        );

        // Backfill the zone on an existing route when newly provided.
        if (! $route->wasRecentlyCreated) {
            $updates = [];
            if ($shippingZoneId !== null && $route->getAttribute('shipping_zone_id') !== $shippingZoneId) {
                $updates['shipping_zone_id'] = $shippingZoneId;
            }
            if ($customerZone !== null && $route->getAttribute('customer_zone') !== $customerZone) {
                $updates['customer_zone'] = $customerZone;
            }
            if ($routeName !== null && $routeName !== '' && $route->getAttribute('route_name') !== $routeName) {
                $updates['route_name'] = $routeName;
            }
            if ($updates !== []) {
                $route->forceFill($updates)->save();
            }
        }

        return $code;
    }

    /**
     * Ensure a shipping zone row exists so the customer FK resolves. The zone
     * description is used to populate the name when the Customer Zone column is
     * provided.
     *
     * @return string|null The normalized zone id, or null when empty.
     */
    private function ensureShippingZoneExists(?string $zoneId, ?string $customerZone = null): ?string
    {
        $id = $this->normalizeCode($zoneId);
        if ($id === null) {
            return null;
        }

        AcumaticaShippingZone::query()->firstOrCreate(
            ['acumatica_id' => $id],
            array_filter([
                'description' => $customerZone,
                'name' => $customerZone,
                'synced_at' => now(),
            ], fn ($value) => $value !== null && $value !== ''),
        );

        return $id;
    }

    /**
     * Upsert the extended customer attributes from the Excel export into the
     * dedicated customer_data table. The 1:1 row is created or updated for the
     * given Acumatica customer id.
     *
     * @param  array<string, mixed>  $fields  The raw mapped row from the upload.
     */
    private function upsertCustomerData(string $acumaticaId, array $fields): void
    {
        $customerZone = $fields['customer_zone'] ?? null;
        $shippingZoneId = $this->ensureShippingZoneExists($fields['zone_id'] ?? null, $customerZone);
        $routeCode = $this->ensureRouteExists(
            $fields['route_code'] ?? null,
            $fields['route_name'] ?? null,
            $shippingZoneId,
            $customerZone,
        );

        $creditLimit = isset($fields['credit_limit']) && $fields['credit_limit'] !== ''
            ? (float) preg_replace('/[^0-9.\-]/', '', $fields['credit_limit'])
            : null;

        $createdOn = null;
        if (isset($fields['created_on']) && $fields['created_on'] !== '') {
            try {
                $createdOn = \Illuminate\Support\Carbon::parse($fields['created_on']);
            } catch (Throwable) {
                $createdOn = null;
            }
        }

        $payload = array_filter([
            'route_code' => $routeCode,
            'shipping_zone_id' => $shippingZoneId,
            'customer_zone' => $fields['customer_zone'] ?? null,
            'customer_group' => $fields['customer_group'] ?? null,
            'tax_registration_id' => $fields['tax_registration_id'] ?? null,
            'currency_id' => $fields['currency_id'] ?? null,
            'price_class_id' => $fields['price_class_id'] ?? null,
            'price_class_name' => $fields['price_class_name'] ?? null,
            'main_ac_owner' => $fields['main_ac_owner'] ?? null,
            'category' => $fields['category'] ?? null,
            'customer_region' => $fields['customer_region'] ?? null,
            'sage_code' => $fields['sage_code'] ?? null,
            'business_account_id' => $fields['business_account_id'] ?? null,
            'credit_limit' => $creditLimit,
            'statement_type' => $fields['statement_type'] ?? null,
            'statement_cycle' => $fields['statement_cycle'] ?? null,
            'shipping_rule' => $fields['shipping_rule'] ?? null,
            'delivery' => $fields['delivery'] ?? null,
            'country' => $fields['country'] ?? null,
            'city' => $fields['city'] ?? null,
            'address_line_1' => $fields['address_line_1'] ?? null,
            'address_line_2' => $fields['address_line_2'] ?? null,
            'address_line_3' => $fields['address_line_3'] ?? null,
            'email' => $fields['email'] ?? null,
            'created_by' => $fields['created_by'] ?? null,
            'created_on' => $createdOn,
            'source' => 'excel_upload',
            'synced_at' => now(),
        ], fn ($value) => $value !== null && $value !== '');

        CustomerData::query()->updateOrCreate(
            ['customer_acumatica_id' => $acumaticaId],
            $payload,
        );
    }

    private function normalizeCode(mixed $code): ?string
    {
        $normalized = strtoupper(trim((string) $code));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeCustomerId(mixed $customerId): ?string
    {
        return $this->normalizeCode($customerId);
    }

    private function detectCustomerRepField(array $customers): ?string
    {
        foreach (self::CUSTOMER_REP_FIELDS as $field) {
            foreach ($customers as $customer) {
                $value = $this->valueFromAcumaticaField($customer[$field] ?? null);
                if ($this->normalizeCode($value) !== null) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function valueFromAcumaticaField(mixed $field): ?string
    {
        $value = AcumaticaClient::scalarVal($field);

        return is_string($value) ? $value : null;
    }

    private function statsForRows(array $rows): array
    {
        return [
            'rows' => count($rows),
            'valid' => count(array_filter($rows, fn ($row) => ($row['status'] ?? null) === 'valid')),
            'errors' => count(array_filter($rows, fn ($row) => ($row['status'] ?? null) === 'error')),
            'create' => count(array_filter($rows, fn ($row) => ($row['action'] ?? null) === 'create')),
            'update' => count(array_filter($rows, fn ($row) => ($row['action'] ?? null) === 'update')),
        ];
    }

    private function notesForSource(string $source): string
    {
        return match ($source) {
            'so_match' => 'Matched from sales orders',
            'customer_endpoint' => 'Matched from Acumatica customer endpoint',
            'upload' => 'Matched from uploaded customer data',
            default => 'Customer assignment matched',
        };
    }

    /** @return list<array<string, mixed>> */
    private function readUploadRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            throw ValidationException::withMessages(['file' => ['Upload CSV, XLS, or XLSX customer data.']]);
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, true);
        if ($rawRows === []) {
            throw ValidationException::withMessages(['file' => ['Upload file is empty.']]);
        }

        $headerRow = array_shift($rawRows);
        $headers = [];
        foreach ($headerRow as $column => $label) {
            $key = $this->headerKey($label);
            if ($key !== null) {
                $headers[$column] = $key;
            }
        }

        if (! in_array('rep_code', $headers, true) || ! in_array('customer_id', $headers, true)) {
            throw ValidationException::withMessages(['file' => ['Upload requires rep_code and customer_id columns.']]);
        }

        $rows = [];
        foreach ($rawRows as $offset => $rawRow) {
            $mapped = ['_row_no' => $offset + 2];
            foreach ($headers as $column => $key) {
                $mapped[$key] = trim((string) ($rawRow[$column] ?? ''));
            }

            if (collect($mapped)->except('_row_no')->filter(fn ($value) => $value !== '')->isEmpty()) {
                continue;
            }

            $rows[] = $mapped;
        }

        return $rows;
    }

    private function headerKey(mixed $label): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $label));

        return match ($key) {
            'repcode', 'employeecode', 'salesrep', 'salesperson' => 'rep_code',
            'customerid', 'customer', 'customercode', 'acumaticacustomerid' => 'customer_id',
            'customername', 'name' => 'customer_name',
            'terms' => 'terms',
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
            'parentcode', 'parentid' => 'parent_code',
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
