<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AcumaticaClient
{
    private const CACHE_KEY = 'acumatica_access_token';
    private const PAGE_SIZE = 100;
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

    private function creditNotesAndMoreTypeClause(): string
    {
        return "(OrderType eq 'QT' or OrderType eq 'RC' or OrderType eq 'CM' or OrderType eq 'PL')";
    }

    private function dateRangeClause(string $dateFrom, string $dateTo): string
    {
        $fromIso = date('Y-m-d', strtotime($dateFrom)).'T00:00:00';
        $toIso   = date('Y-m-d', strtotime($dateTo)).'T23:59:59';

        return "Date ge datetimeoffset'{$fromIso}' and Date le datetimeoffset'{$toIso}'";
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

        if (in_array($entity, ['StockItem', 'CustomerClass'], true)) {
            unset($query['$select']);
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

    private function get(string $path, array $query = []): array
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
            ->timeout(30)
            ->get($url);

        if ($response->status() === 401) {
            Cache::forget(self::CACHE_KEY);
            $response = Http::withToken($this->authenticate())
                ->timeout(30)
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
            '$filter' => "CustomerID eq '{$customerId}'",
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
            '$filter' => "OrderNbr eq '{$orderNbr}'",
        ]));

        return $results[0] ?? null;
    }

    /**
     * Fetch all customers across all pages.
     */
    public function fetchAllCustomers(): array
    {
        $all = [];
        $skip = 0;

        do {
            $page = $this->fetchCustomers($skip);
            $all = array_merge($all, $page);
            $skip += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE);

        return $all;
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

    public function fetchCreditNotesAndMoreByDateRange(string $dateFrom, string $dateTo, int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('SalesOrder', $this->salesOrderListParams([
            '$top'    => $top,
            '$skip'   => $skip,
            '$filter' => $this->creditNotesAndMoreTypeClause().' and '.$this->dateRangeClause($dateFrom, $dateTo),
        ]));
    }

    public function fetchAllCreditNotesAndMoreByDateRange(string $dateFrom, string $dateTo): array
    {
        $all = [];
        $skip = 0;

        do {
            $page = $this->fetchCreditNotesAndMoreByDateRange($dateFrom, $dateTo, $skip);
            $all = array_merge($all, $page);
            $skip += self::PAGE_SIZE;
            usleep(500_000);
        } while (count($page) === self::PAGE_SIZE);

        return $all;
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
    public function fetchAllSalesOrdersByDateRange(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchSalesOrdersByDateRange($dateFrom, $dateTo, $skip),
        );
    }

    /**
     * Fetch all sales orders for a specific customer.
     */
    public function fetchAllSalesOrdersForCustomer(string $customerId): array
    {
        $all = [];
        $skip = 0;

        do {
            $page = $this->get('SalesOrder', $this->salesOrderListParams([
                '$top'    => self::PAGE_SIZE,
                '$skip'   => $skip,
                '$filter' => "CustomerID eq '{$customerId}' and ".$this->salesOrderTypeClause(),
            ]));
            $all = array_merge($all, $page);
            $skip += self::PAGE_SIZE;
            usleep(500_000);
        } while (count($page) === self::PAGE_SIZE);

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

    public function fetchAllOpenSalesOrdersForBackorders(): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchOpenSalesOrdersForBackorders($skip),
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

    public function fetchOrdersForFillRate(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchOrdersForFillRatePage($dateFrom, $dateTo, $skip),
        );
    }

    // -------------------------------------------------------------------------
    // Inventory endpoints
    // -------------------------------------------------------------------------

    public function fetchActiveInventoryItems(int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        $top = min($top, self::MAX_CHUNK_SIZE);

        try {
            return $this->get('InventoryItem', [
                '$top'    => $top,
                '$skip'   => $skip,
                '$filter' => "ItemStatus eq 'Active'",
            ]);
        } catch (RuntimeException) {
            return $this->fetchStockItems($skip, $top);
        }
    }

    public function fetchStockItems(int $skip = 0, int $top = self::PAGE_SIZE): array
    {
        return $this->get('StockItem', [
            '$top'  => min($top, self::MAX_CHUNK_SIZE),
            '$skip' => $skip,
        ]);
    }

    public function fetchAllActiveInventoryItems(): array
    {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchActiveInventoryItems($skip),
        );
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
    private function fetchAllPages(callable $fetchPage): array
    {
        $all = [];
        $skip = 0;
        $pages = 0;

        do {
            $page = $fetchPage($skip);
            $all = array_merge($all, $page);
            $skip += self::PAGE_SIZE;
            $pages++;
            usleep(self::INTER_PAGE_DELAY_US);
        } while (count($page) === self::PAGE_SIZE && $pages < self::MAX_PAGES);

        return $all;
    }
}
