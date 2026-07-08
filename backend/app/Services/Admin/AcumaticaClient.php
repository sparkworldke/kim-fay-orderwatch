<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AcumaticaClient
{
    private const CACHE_KEY = 'acumatica_access_token';
    private const PAGE_SIZE = 100;
    private const INVENTORY_PAGE_SIZE = 50; // smaller pages to avoid SSL timeouts on large datasets
    private const MAX_CHUNK_SIZE = 200;
    private const MAX_PAGES = 500;
    private const INTER_PAGE_DELAY_US = 500_000;

    public function __construct(
        private readonly AcumaticaService $acumaticaService,
        private readonly EncryptionService $encryption,
    ) {
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    private function authenticate(): string
    {
        $config = $this->acumaticaService->config();

        $response = Http::asForm()
            ->timeout(20)
            ->post($config->token_url, [
                'grant_type'    => $config->grant_type,
                'client_id'     => $this->encryption->decrypt($config->client_id_encrypted) ?? '',
                'client_secret' => $this->encryption->decrypt($config->client_secret_encrypted) ?? '',
                'username'      => $config->username,
                'password'      => $this->encryption->decrypt($config->password_encrypted) ?? '',
                'scope'         => $config->scope,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Acumatica auth failed: {$response->status()} {$response->reason()}");
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Acumatica auth response missing access_token');
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 60);
        Cache::put(self::CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function getToken(): string
    {
        return Cache::get(self::CACHE_KEY) ?? $this->authenticate();
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function entityBase(): string
    {
        $c = $this->acumaticaService->config();

        return rtrim($c->base_url, '/') . "/entity/{$c->endpoint}/{$c->version}";
    }

    /**
     * Perform a GET against the Acumatica contract API, retrying once on 401.
     *
     * Query params are appended manually so OData keys ($filter, $expand, etc.)
     * are never percent-encoded — Guzzle's array param encoding turns '$' into
     * '%24' which Acumatica does not accept.
     */
    /** OData fragment restricting SalesOrder queries to in-scope document types. */
    private function salesOrderTypeClause(): string
    {
        return "OrderType eq 'SO'";
    }

    /**
     * @param  list<string>|null  $documentTypes
     */
    private function creditNotesAndMoreTypeClause(?array $documentTypes = null): string
    {
        $allowed = ['QT', 'RC', 'CM', 'PL'];
        $types = $documentTypes
            ? array_values(array_intersect(array_map(fn ($type) => strtoupper(trim((string) $type)), $documentTypes), $allowed))
            : $allowed;

        if ($types === []) {
            $types = $allowed;
        }

        return '(' . implode(' or ', array_map(fn (string $type) => "OrderType eq '{$type}'", $types)) . ')';
    }

    private function dateRangeClause(string $dateFrom, string $dateTo): string
    {
        $fromIso = date('Y-m-d', strtotime($dateFrom)).'T00:00:00';
        $toIso   = date('Y-m-d', strtotime($dateTo)).'T23:59:59';

        return "Date ge datetimeoffset'{$fromIso}' and Date le datetimeoffset'{$toIso}'";
    }

    private function quoteODataValue(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function normalizeQuery(string $path, array $query): array
    {
        $entity = trim($path, '/');

        if ($entity === 'SalesOrder') {
            // IpayV2 22.200.001 exposes line items as Details (DocumentDetails is invalid).
            if (($query['$expand'] ?? null) === 'DocumentDetails') {
                $query['$expand'] = 'Details';
            }

            $select = (string) ($query['$select'] ?? '');
            if (str_contains($select, 'Details/') || str_contains($select, 'DocumentDetails/')) {
                unset($query['$select']);
            }
        }

        if (in_array($entity, ['StockItem', 'CustomerClass', 'InventoryItem', 'Zone', 'ShippingZone', 'ShipZone'], true)) {
            unset($query['$select']);
        }

        if ($entity === 'InventoryItem') {
            $expand = (string) ($query['$expand'] ?? '');
            if (in_array($expand, ['ItemWarehouseDetails', 'InventoryItemWarehouseDetails'], true)) {
                $query['$expand'] = 'WarehouseDetails';
            }
        }

        return $query;
    }

    /**
     * IpayV2 exposes line items as Details.
     * Never add nested $select paths — they fail OData binding on 22.200.001.
     */
    private function salesOrderListParams(array $params): array
    {
        if (($params['$expand'] ?? null) === 'DocumentDetails') {
            $params['$expand'] = 'Details';
        }

        if (! isset($params['$expand'])) {
            $params['$expand'] = 'Details';
        }

        return $params;
    }

    private function get(string $path, array $query = [], int $timeoutSeconds = 120): array
    {
        $query = $this->normalizeQuery($path, $query);
        $url = $this->entityBase() . '/' . ltrim($path, '/') . '/';

        if (! empty($query)) {
            $parts = [];
            foreach ($query as $key => $value) {
                // OData values must not have commas or single quotes encoded —
                // encode only characters that would break URL structure.
                $encoded = str_replace(
                    ['%2C', '%27', '%20'],
                    [',',   "'",   ' '],
                    rawurlencode((string) $value)
                );
                $parts[] = $key . '=' . $encoded;
            }
            $url .= '?' . implode('&', $parts);
        }

        \Illuminate\Support\Facades\Log::debug('Acumatica GET', ['url' => $url]);

        $response = Http::withToken($this->getToken())
            ->timeout($timeoutSeconds)
            ->get($url);

        if ($response->status() === 401) {
            Cache::forget(self::CACHE_KEY);
            $response = Http::withToken($this->authenticate())
                ->timeout($timeoutSeconds)
                ->get($url);
        }

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::error('Acumatica response body', ['body' => $response->body()]);
            throw new RuntimeException("Acumatica GET {$path} failed: {$response->status()} {$response->reason()}");
        }

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // Field extraction
    // -------------------------------------------------------------------------

    /**
     * Extract the scalar `.value` from an Acumatica field object.
     */
    public static function val(mixed $field): mixed
    {
        if (is_array($field) && array_key_exists('value', $field)) {
            return $field['value'];
        }

        return $field;
    }

    /**
     * Extract a trimmed string from an Acumatica field, including expanded/nested objects.
     *
     * Returns null for empty arrays, nested entity payloads, and other non-scalar values.
     */
    public static function scalarVal(mixed $field): ?string
    {
        if ($field === null || $field === []) {
            return null;
        }

        if (is_string($field) || is_int($field) || is_float($field)) {
            $string = trim((string) $field);

            return $string !== '' ? $string : null;
        }

        if (! is_array($field)) {
            return null;
        }

        if (array_key_exists('value', $field)) {
            return self::scalarVal($field['value']);
        }

        foreach (['ZoneID', 'ShippingZoneID', 'Description'] as $nestedKey) {
            if (array_key_exists($nestedKey, $field)) {
                $nested = self::scalarVal($field[$nestedKey]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Customer endpoints
    // -------------------------------------------------------------------------

    /**
     * Fetch one page of customers.
     */
    public function fetchCustomers(int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('Customer', [
            '$top'  => $top,
            '$skip' => $skip,
        ]);
    }

    /**
     * Fetch a single customer by CustomerID.
     */
    public function fetchCustomer(string $customerId): ?array
    {
        $results = $this->get('Customer', [
            '$top'    => 1,
            '$filter' => "CustomerID eq '".$this->quoteODataValue($customerId)."'",
        ]);

        return $results[0] ?? null;
    }

    /**
     * Fetch a single sales order by OrderNbr (for probing the response structure).
     */
    public function fetchOrderByNumber(string $orderNbr): ?array
    {
        $results = $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => 1,
            '$filter' => "OrderNbr eq '".$this->quoteODataValue($orderNbr)."'",
        ]));

        return $results[0] ?? null;
    }

    /**
     * Fetch a single record from any configured endpoint entity by exact field value.
     */
    public function fetchFirstByField(string $entity, string $field, string $value): ?array
    {
        $results = $this->get($entity, [
            '$top'    => 1,
            '$filter' => "{$field} eq '".$this->quoteODataValue($value)."'",
        ]);

        return $results[0] ?? null;
    }

    /**
     * Fetch one generic entity page from the configured Acumatica endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchEntityPage(string $entity, int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get($entity, [
            '$top' => min($top, self::MAX_CHUNK_SIZE),
            '$skip' => $skip,
        ]);
    }

    /**
     * Fetch all records for a generic Acumatica entity.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllEntity(string $entity, ?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchEntityPage($entity, $skip),
            $onProgress,
        );
    }

    /**
     * Fetch all customers across all pages.
     */
    public function fetchAllCustomers(?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchCustomers($skip),
            $onProgress,
        );
    }

    // -------------------------------------------------------------------------
    // Customer category endpoints
    // -------------------------------------------------------------------------

    public function fetchAllCustomerCategories(): array
    {
        $all = [];
        $skip = 0;

        do {
            $page = $this->get('CustomerClass', [
                '$top'    => self::PAGE_SIZE,
                '$skip'   => $skip,
                '$select' => 'ClassID,Description',
            ]);
            $all = array_merge($all, $page);
            $skip += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE);

        return $all;
    }

    /**
     * Fetch all shipping zones from the first available Acumatica entity.
     *
     * IpayV2 often omits the Zone entity (404); ShippingZone and ShipZone are tried next.
     * Returns an empty list when no entity is exposed — callers should fall back to
     * Customer.ShippingZoneID.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllShippingZones(): array
    {
        foreach (['Zone', 'ShippingZone', 'ShipZone'] as $entity) {
            try {
                return $this->fetchAllPages(
                    fn (int $skip) => $this->get($entity, [
                        '$top' => self::PAGE_SIZE,
                        '$skip' => $skip,
                    ]),
                );
            } catch (RuntimeException $e) {
                if (! $this->isNotFoundError($e)) {
                    throw $e;
                }
            }
        }

        return [];
    }

    private function isNotFoundError(RuntimeException $e): bool
    {
        return str_contains($e->getMessage(), '404');
    }

    // -------------------------------------------------------------------------
    // Product category (ItemClass) endpoints
    // -------------------------------------------------------------------------

    public function fetchAllItemClasses(): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->get('ItemClass', [
                '$top'  => self::PAGE_SIZE,
                '$skip' => $skip,
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Sales order endpoints
    // -------------------------------------------------------------------------

    /**
     * Fetch one page of sales orders filtered by date range.
     * Acumatica OData date filter uses ISO format without timezone.
     */
    public function fetchSalesOrdersByDateRange(string $dateFrom, string $dateTo, int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => $top,
            '$skip'   => $skip,
            '$filter' => $this->salesOrderTypeClause().' and '.$this->dateRangeClause($dateFrom, $dateTo),
        ]));
    }

    /**
     * @param  list<string>|null  $documentTypes
     */
    public function fetchCreditNotesAndMoreByDateRange(string $dateFrom, string $dateTo, int $skip = 0, int $top = self::PAGE_SIZE, ?array $documentTypes = null): array
    {
        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => $top,
            '$skip'   => $skip,
            '$filter' => $this->creditNotesAndMoreTypeClause($documentTypes).' and '.$this->dateRangeClause($dateFrom, $dateTo),
        ]));
    }

    /**
     * @param  list<string>|null  $documentTypes
     */
    public function fetchAllCreditNotesAndMoreByDateRange(string $dateFrom, string $dateTo, ?callable $onProgress = null, ?array $documentTypes = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchCreditNotesAndMoreByDateRange($dateFrom, $dateTo, $skip, documentTypes: $documentTypes),
            $onProgress,
        );
    }

    public function fetchSalesOrdersForCustomerByDateRange(string $customerId, string $dateFrom, string $dateTo, int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => $top,
            '$skip'   => $skip,
            '$filter' => "CustomerID eq '{$customerId}' and ".$this->salesOrderTypeClause().' and '.$this->dateRangeClause($dateFrom, $dateTo),
        ]));
    }

    /**
     * Fetch all sales orders in a date range across pages.
     */
    public function fetchAllSalesOrdersByDateRange(string $dateFrom, string $dateTo, ?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchSalesOrdersByDateRange($dateFrom, $dateTo, $skip),
            $onProgress,
        );
    }

    /**
     * Fetch all sales orders for a specific customer.
     */
    public function fetchAllSalesOrdersForCustomer(string $customerId, ?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->get('SalesOrder', $this->salesOrderListParams([
                '$top'    => self::PAGE_SIZE,
                '$skip'   => $skip,
                '$filter' => "CustomerID eq '".$this->quoteODataValue($customerId)."' and ".$this->salesOrderTypeClause(),
            ])),
            $onProgress,
        );
    }

    /**
     * @param  list<string>  $orderNbrs
     * @return list<array<string, mixed>>
     */
    public function fetchSalesOrdersByNumbers(array $orderNbrs, ?callable $onProgress = null): array
    {
        $orderNbrs = array_values(array_unique(array_filter($orderNbrs, fn ($value) => is_string($value) && $value !== '')));

        if ($orderNbrs === []) {
            return [];
        }

        $all = [];

        foreach (array_chunk($orderNbrs, 20) as $chunk) {
            $filter = implode(' or ', array_map(
                fn (string $orderNbr) => "OrderNbr eq '".$this->quoteODataValue($orderNbr)."'",
                $chunk,
            ));

            $all = array_merge($all, $this->fetchAllPages(
                fn (int $skip) => $this->get('SalesOrder', $this->salesOrderListParams([
                    '$top'    => self::PAGE_SIZE,
                    '$skip'   => $skip,
                    '$filter' => $filter,
                ])),
                $onProgress,
            ));
        }

        return $all;
    }

    // -------------------------------------------------------------------------
    // Backorder endpoints
    // -------------------------------------------------------------------------

    public function openSalesOrdersForBackordersFilter(): string
    {
        return $this->salesOrderTypeClause()
            ." and Status ne 'Completed' and Status ne 'Cancelled' and Status ne 'Canceled' and Status ne 'Rejected'";
    }

    public function fetchOpenSalesOrdersForBackorders(int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => min($top, self::MAX_CHUNK_SIZE),
            '$skip'   => $skip,
            '$filter' => $this->openSalesOrdersForBackordersFilter(),
        ]));
    }

    public function fetchOpenSalesOrdersForBackordersByDateRange(string $dateFrom, string $dateTo, int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => min($top, self::MAX_CHUNK_SIZE),
            '$skip'   => $skip,
            '$filter' => $this->openSalesOrdersForBackordersFilter().' and '.$this->dateRangeClause($dateFrom, $dateTo),
        ]));
    }

    public function fetchAllOpenSalesOrdersForBackorders(?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchOpenSalesOrdersForBackorders($skip),
            $onProgress,
        );
    }

    public function fetchAllOpenSalesOrdersForBackordersByDateRange(string $dateFrom, string $dateTo, ?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchOpenSalesOrdersForBackordersByDateRange($dateFrom, $dateTo, $skip),
            $onProgress,
        );
    }

    /** @deprecated Use fetchAllOpenSalesOrdersForBackorders — derives backorders from line qty fields. */
    public function fetchAllBackorders(): array
    {
        return $this->fetchAllOpenSalesOrdersForBackorders();
    }

    // -------------------------------------------------------------------------
    // Fill rate endpoints
    // -------------------------------------------------------------------------

    public function fetchOrdersForFillRatePage(string $dateFrom, string $dateTo, int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        $fromIso = date('Y-m-d', strtotime($dateFrom)) . 'T00:00:00';
        $toIso   = date('Y-m-d', strtotime($dateTo))   . 'T23:59:59';

        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => $top,
            '$skip'   => $skip,
            '$filter' => $this->salesOrderTypeClause()." and Date ge datetimeoffset'{$fromIso}' and Date le datetimeoffset'{$toIso}' and Status ne 'Completed'",
        ]));
    }

    public function fetchOrdersForFillRate(string $dateFrom, string $dateTo, ?callable $onProgress = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchOrdersForFillRatePage($dateFrom, $dateTo, $skip),
            $onProgress,
        );
    }

    // -------------------------------------------------------------------------
    // Inventory endpoints
    // -------------------------------------------------------------------------

    public function fetchActiveInventoryItems(
        int $skip = 0,
        int $top = self::INVENTORY_PAGE_SIZE,
        ?string $warehouseId = null,
        ?string $itemClass = null,
    ): array {
        $top = min($top, self::MAX_CHUNK_SIZE);

        $filterParts = ["ItemStatus eq 'Active'"];
        if ($warehouseId !== null) {
            $filterParts[] = "DefaultWarehouseID eq '" . $this->quoteODataValue($warehouseId) . "'";
        }
        if ($itemClass !== null) {
            $filterParts[] = "ItemClass eq '" . $this->quoteODataValue($itemClass) . "'";
        }
        $filterString = implode(' and ', $filterParts);

        $base = [
            '$top'    => $top,
            '$skip'   => $skip,
            '$filter' => $filterString,
        ];

        foreach (['WarehouseDetails', null] as $expand) {
            try {
                $query = $base;
                if ($expand !== null) {
                    $query['$expand'] = $expand;
                }
                return $this->getWithRetry('InventoryItem', $query);
            } catch (RuntimeException) {
                continue;
            }
        }

        foreach (['StockItem', 'stockItem'] as $stockItemEntity) {
            try {
                return $this->getWithRetry($stockItemEntity, [
                    '$top'    => $top,
                    '$skip'   => $skip,
                    '$filter' => $filterString,
                    '$expand' => 'WarehouseDetails',
                ]);
            } catch (RuntimeException) {
                continue;
            }
        }

        return $this->fetchStockItems($skip, $top, $warehouseId, $itemClass);
    }

    public function fetchStockItems(
        int $skip = 0,
        int $top = self::INVENTORY_PAGE_SIZE,
        ?string $warehouseId = null,
        ?string $itemClass = null,
    ): array
    {
        $query = [
            '$top'  => min($top, self::MAX_CHUNK_SIZE),
            '$skip' => $skip,
            '$expand' => 'WarehouseDetails',
        ];

        $filterParts = [];
        if ($warehouseId !== null) {
            $filterParts[] = "DefaultWarehouseID eq '" . $this->quoteODataValue($warehouseId) . "'";
        }
        if ($itemClass !== null) {
            $filterParts[] = "ItemClass eq '" . $this->quoteODataValue($itemClass) . "'";
        }
        if ($filterParts !== []) {
            $query['$filter'] = implode(' and ', $filterParts);
        }

        foreach (['stockItem', 'StockItem'] as $stockItemEntity) {
            try {
                return $this->getWithRetry($stockItemEntity, $query);
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException('Acumatica stock item endpoint is unavailable.');
    }

    public function fetchAllActiveInventoryItems(?string $warehouseId = null, ?string $itemClass = null): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchActiveInventoryItems($skip, self::INVENTORY_PAGE_SIZE, warehouseId: $warehouseId, itemClass: $itemClass),
            pageSize: self::INVENTORY_PAGE_SIZE,
        );
    }

    /** GET with one automatic retry on connection timeout (cURL 28). */
    private function getWithRetry(string $path, array $query = []): array
    {
        try {
            return $this->get($path, $query);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'cURL error 28') || str_contains($e->getMessage(), 'timed out')) {
                \Illuminate\Support\Facades\Log::warning('Acumatica GET timeout — retrying once', [
                    'path' => $path,
                    'skip' => $query['$skip'] ?? null,
                ]);
                usleep(2_000_000); // 2 s cooldown before retry
                return $this->get($path, $query);
            }
            throw $e;
        }
    }

    public function fetchAllStockItems(): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchStockItems($skip),
        );
    }

    /**
     * @param  callable(int): array<int, array<string, mixed>>  $fetchPage
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(callable $fetchPage, ?callable $onProgress = null, int $pageSize = self::PAGE_SIZE): array
    {
        $all = [];
        $skip = 0;
        $pages = 0;

        do {
            if ($onProgress !== null) {
                $onProgress();
            }
            $page = $fetchPage($skip);
            $all = array_merge($all, $page);
            $skip += $pageSize;
            $pages++;
            usleep(self::INTER_PAGE_DELAY_US);
        } while (count($page) === $pageSize && $pages < self::MAX_PAGES);

        return $all;
    }
}
