<?php

namespace App\Services\Admin;

use App\Services\Dtc\DtcQuoteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AcumaticaClient
{
    private const CACHE_KEY = 'acumatica_access_token';
    /** Cache key for entities known to be missing / unauthorized on this endpoint. */
    private const UNAVAILABLE_ENTITIES_CACHE_KEY = 'acumatica_unavailable_entities';
    private const PAGE_SIZE = 100;
    private const INVENTORY_PAGE_SIZE = 50; // smaller pages to avoid SSL timeouts on large datasets
    private const MAX_CHUNK_SIZE = 200;
    private const MAX_PAGES = 500;
    private const INTER_PAGE_DELAY_US = 500_000;

    /** Entities that are commonly probed and may be absent on IpayV2. */
    private const PROBE_ENTITIES = [
        'InventoryItem',
        'ItemClass',
        'Zone',
        'ShippingZone',
        'ShipZone',
    ];

    public function __construct(
        private readonly AcumaticaService $acumaticaService,
        private readonly EncryptionService $encryption,
    ) {
    }

    private function isEntityUnavailable(string $entity): bool
    {
        $map = Cache::get(self::UNAVAILABLE_ENTITIES_CACHE_KEY, []);

        return is_array($map) && isset($map[$entity]);
    }

    private function markEntityUnavailable(string $entity, string $reason): void
    {
        $map = Cache::get(self::UNAVAILABLE_ENTITIES_CACHE_KEY, []);
        if (! is_array($map)) {
            $map = [];
        }
        if (isset($map[$entity])) {
            return;
        }
        $map[$entity] = [
            'reason' => mb_substr($reason, 0, 200),
            'at' => now()->toIso8601String(),
        ];
        // Remember for 6 hours so warehouse cron loops stop hammering missing entities.
        Cache::put(self::UNAVAILABLE_ENTITIES_CACHE_KEY, $map, now()->addHours(6));
        Log::warning('Acumatica entity marked unavailable for this endpoint', [
            'entity' => $entity,
            'reason' => $map[$entity]['reason'],
        ]);
    }

    private function isExpectedProbeFailure(string $path, string $body, int $status): bool
    {
        $entity = trim(explode('?', $path)[0], '/');
        if (! in_array($entity, self::PROBE_ENTITIES, true)) {
            return false;
        }

        if ($status === 403 || $status === 404) {
            return true;
        }

        return str_contains($body, 'Entity ')
            && str_contains($body, 'not found');
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
        $entity = trim(explode('?', ltrim($path, '/'))[0], '/');
        if ($this->isEntityUnavailable($entity)) {
            throw new RuntimeException("Acumatica GET {$path} failed: entity unavailable (cached)");
        }

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
            $body = $response->body();
            $status = $response->status();
            $expected = $this->isExpectedProbeFailure($path, $body, $status);

            if ($expected) {
                $this->markEntityUnavailable($entity, $body !== '' ? $body : "HTTP {$status}");
                Log::debug('Acumatica probe response body', [
                    'entity' => $entity,
                    'status' => $status,
                    'body' => $body,
                ]);
            } else {
                Log::error('Acumatica response body', ['body' => $body]);
            }

            throw new RuntimeException("Acumatica GET {$path} failed: {$status} {$response->reason()}");
        }

        return $response->json() ?? [];
    }

    /**
     * PUT against the Acumatica contract API (create/update), retrying once on 401.
     * Pattern matches the WooCommerce iPay plugin (PosOrder PUT) used in production.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>|object  $payload  Empty object {} for inquiry entities (plugin style)
     */
    public function put(string $path, array|object $payload = [], array $query = [], int $timeoutSeconds = 120): array
    {
        $query = $this->normalizeQuery($path, $query);
        $url = $this->entityBase() . '/' . ltrim($path, '/') . '/';

        if (! empty($query)) {
            $parts = [];
            foreach ($query as $key => $value) {
                $encoded = str_replace(
                    ['%2C', '%27', '%20'],
                    [',',   "'",   ' '],
                    rawurlencode((string) $value)
                );
                $parts[] = $key . '=' . $encoded;
            }
            $url .= '?' . implode('&', $parts);
        }

        \Illuminate\Support\Facades\Log::info('Acumatica PUT', [
            'url' => $url,
            'entity' => $path,
        ]);

        $response = Http::withToken($this->getToken())
            ->timeout($timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->put($url, $payload);

        if ($response->status() === 401) {
            Cache::forget(self::CACHE_KEY);
            $response = Http::withToken($this->authenticate())
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->put($url, $payload);
        }

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::error('Acumatica PUT failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException("Acumatica PUT {$path} failed: {$response->status()} {$response->reason()} — ".$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Create a Sales Order in Acumatica for a KP customer + line items.
     * Inspired by inspo/wc-ipay-accumatica createOrder (PUT + Customer + lines).
     *
     * IpayV2 SalesOrder uses CustomerID + Details (OrderQty), not PosOrder DocumentDetails.
     *
     * @param  list<array{inventory_id: string, qty: float|int, unit_price?: float|null, warehouse_id?: string|null, description?: string|null}>  $lines
     * @return array{order_nbr: string, order_id: ?string, raw: array<string, mixed>}
     */
    /**
     * Builds the Ship-To Contact/Address override block for a SalesOrder/PosOrder payload,
     * matching the fields exposed on Acumatica's own Ship-To Contact/Address screens.
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, address_line1?: ?string, address_line2?: ?string}  $shipTo
     * @return array<string, mixed>
     */
    private function shipToOverridePayload(array $shipTo): array
    {
        $contact = array_filter([
            'FullName' => isset($shipTo['name']) && $shipTo['name'] !== '' ? ['value' => $shipTo['name']] : null,
            'Phone1' => isset($shipTo['phone']) && $shipTo['phone'] !== '' ? ['value' => $shipTo['phone']] : null,
            'Email' => isset($shipTo['email']) && $shipTo['email'] !== '' ? ['value' => $shipTo['email']] : null,
        ]);
        $address = array_filter([
            'AddressLine1' => isset($shipTo['address_line1']) && $shipTo['address_line1'] !== '' ? ['value' => $shipTo['address_line1']] : null,
            'AddressLine2' => isset($shipTo['address_line2']) && $shipTo['address_line2'] !== '' ? ['value' => $shipTo['address_line2']] : null,
        ]);

        $payload = [];
        if ($contact !== []) {
            $payload['ShipToContactOverride'] = ['value' => true];
            $payload['ShipToContact'] = $contact;
        }
        if ($address !== []) {
            $payload['ShipToAddressOverride'] = ['value' => true];
            $payload['ShipToAddress'] = $address;
        }

        return $payload;
    }

    /**
     * @param  ?array{name?: ?string, phone?: ?string, email?: ?string, address_line1?: ?string, address_line2?: ?string}  $shipTo
     */
    public function createSalesOrder(
        string $customerId,
        array $lines,
        ?string $customerOrder = null,
        ?string $description = null,
        string $orderType = 'SO',
        bool $zeroPrice = true,
        ?array $shipTo = null,
    ): array {
        if ($lines === []) {
            throw new RuntimeException('Cannot create sales order without line items.');
        }

        $details = [];
        foreach ($lines as $line) {
            $inventoryId = trim((string) ($line['inventory_id'] ?? ''));
            $qty = (float) ($line['qty'] ?? 0);
            if ($inventoryId === '' || $qty <= 0) {
                continue;
            }

            $detail = [
                'InventoryID' => ['value' => $inventoryId],
                'OrderQty' => ['value' => $qty],
            ];

            if ($zeroPrice || array_key_exists('unit_price', $line)) {
                $detail['UnitPrice'] = ['value' => (float) ($line['unit_price'] ?? 0)];
            }
            if (! empty($line['warehouse_id'])) {
                $detail['WarehouseID'] = ['value' => (string) $line['warehouse_id']];
            }
            if (! empty($line['description'])) {
                $detail['LineDescription'] = ['value' => (string) $line['description']];
            }

            $details[] = $detail;
        }

        if ($details === []) {
            throw new RuntimeException('Cannot create sales order: no valid line items.');
        }

        // IpayV2 SalesOrder uses Details + OrderQty (DocumentDetails is invalid on this entity).
        // WC iPay plugin used PosOrder + DocumentDetails + Quantity for e‑commerce — different entity.
        $payload = [
            'OrderType' => ['value' => $orderType],
            'CustomerID' => ['value' => $customerId],
            'Details' => $details,
        ];

        if ($customerOrder !== null && $customerOrder !== '') {
            $payload['CustomerOrder'] = ['value' => $customerOrder];
            $payload['ExternalRef'] = ['value' => $customerOrder];
        }
        if ($description !== null && $description !== '') {
            $payload['Description'] = ['value' => $description];
        }
        if ($shipTo !== null) {
            $payload = [...$payload, ...$this->shipToOverridePayload($shipTo)];
        }

        $response = $this->put('SalesOrder', $payload, [
            '$expand' => 'Details',
        ]);

        $orderNbr = self::scalarVal($response['OrderNbr'] ?? null);
        if ($orderNbr === null || $orderNbr === '') {
            throw new RuntimeException('Acumatica created a sales order but OrderNbr was missing: '.json_encode($response));
        }

        return [
            'order_nbr' => $orderNbr,
            'order_id' => isset($response['id']) ? (string) $response['id'] : null,
            'raw' => $response,
        ];
    }

    /**
     * DTCACCOUNT sales prices — mirrors inspo/acumatica/acumatica.php fetch_prices():
     *
     *   PUT {entityBase}/SalesPricesInquiry?$expand=SalesPriceDetails
     *   body: {}   (json_encode(new stdClass()))
     *   then keep SalesPriceDetails where PriceCode == DTCACCOUNT
     *
     * Important differences vs generic put():
     * - No trailing slash before ? (plugin URL shape)
     * - Raw body "{}" (not [])
     * - 600s timeout like the plugin cURL call
     *
     * @return list<array<string, mixed>>
     */
    public function fetchDtcPrices(): array
    {
        // Plugin: '/' . trim($endpoint_base,'/') . '/SalesPricesInquiry?$expand=SalesPriceDetails'
        // Our previous put() built .../SalesPricesInquiry/?$expand=... (trailing slash) — avoid that.
        $url = rtrim($this->entityBase(), '/').'/SalesPricesInquiry?$expand=SalesPriceDetails';

        Log::info('Acumatica PUT', [
            'url' => $url,
            'entity' => 'SalesPricesInquiry',
            'style' => 'plugin_fetch_prices',
            'body' => '{}',
        ]);

        $response = $this->putSalesPricesInquiry($url, '{}');

        // If empty expand result, retry with explicit class filter (plugin commented fallback).
        $details = $this->extractSalesPriceDetails($response);
        if ($details === []) {
            $filterBody = json_encode([
                'PriceType' => ['value' => 'Customer Price Class'],
                'PriceCode' => ['value' => 'DTCACCOUNT'],
            ], JSON_THROW_ON_ERROR);

            Log::info('Acumatica PUT SalesPricesInquiry retry with PriceCode filter', [
                'url' => $url,
                'body' => $filterBody,
            ]);

            $response = $this->putSalesPricesInquiry($url, $filterBody);
            $details = $this->extractSalesPriceDetails($response);
        }

        if ($details === []) {
            // Last resort: PriceType "All Prices" header style seen in plugin tmp/prices.json.
            $allBody = json_encode([
                'PriceType' => ['value' => 'All Prices'],
            ], JSON_THROW_ON_ERROR);

            Log::info('Acumatica PUT SalesPricesInquiry retry All Prices', [
                'url' => $url,
            ]);

            $response = $this->putSalesPricesInquiry($url, $allBody);
            $details = $this->extractSalesPriceDetails($response);
        }

        $rows = [];
        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            if (strcasecmp((string) self::scalarVal($detail['PriceCode'] ?? null), 'DTCACCOUNT') === 0) {
                $rows[] = $detail;
            }
        }

        Log::info('Acumatica SalesPricesInquiry parsed', [
            'details_total' => count($details),
            'dtcaccount_rows' => count($rows),
            'sample_codes' => array_values(array_unique(array_filter(array_map(
                fn ($d) => is_array($d) ? (string) self::scalarVal($d['PriceCode'] ?? null) : null,
                array_slice($details, 0, 20),
            )))),
        ]);

        return $rows;
    }

    /**
     * Plugin-style PUT for SalesPricesInquiry (raw JSON body, long timeout, no trailing slash).
     *
     * @return array<string, mixed>
     */
    private function putSalesPricesInquiry(string $url, string $jsonBody): array
    {
        $timeout = 600;

        $response = Http::withToken($this->getToken())
            ->timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withBody($jsonBody, 'application/json')
            ->put($url);

        if ($response->status() === 401) {
            Cache::forget(self::CACHE_KEY);
            $response = Http::withToken($this->authenticate())
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->withBody($jsonBody, 'application/json')
                ->put($url);
        }

        if (! $response->successful()) {
            Log::error('Acumatica PUT SalesPricesInquiry failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 2000),
            ]);
            throw new RuntimeException(
                "Acumatica PUT SalesPricesInquiry failed: {$response->status()} {$response->reason()} — ".
                mb_substr($response->body(), 0, 500)
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Acumatica SalesPricesInquiry returned non-JSON body.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function extractSalesPriceDetails(array $response): array
    {
        // Single inquiry object (plugin tmp/prices.json shape).
        if (is_array($response['SalesPriceDetails'] ?? null)) {
            return array_values(array_filter(
                $response['SalesPriceDetails'],
                static fn ($row) => is_array($row),
            ));
        }

        // Some contracts wrap a list of inquiry results.
        if ($response !== [] && array_is_list($response)) {
            $out = [];
            foreach ($response as $inquiry) {
                if (! is_array($inquiry)) {
                    continue;
                }
                foreach (is_array($inquiry['SalesPriceDetails'] ?? null) ? $inquiry['SalesPriceDetails'] : [] as $detail) {
                    if (is_array($detail)) {
                        $out[] = $detail;
                    }
                }
            }

            return $out;
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function fetchQuote(string $quoteNbr): array
    {
        $safe = $this->quoteODataValue($quoteNbr);
        $rows = $this->get('SalesOrder', [
            '$expand' => 'Details',
            '$filter' => "OrderType eq 'QT' and OrderNbr eq '{$safe}'",
            '$top' => 1,
        ]);
        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /** @return array<string, mixed> */
    public function fetchQuoteWithCustomerDetails(string $quoteNbr): array
    {
        $safe = $this->quoteODataValue($quoteNbr);
        try {
            $rows = $this->get('SalesOrder', [
                '$expand' => 'Details,CustomerDetails',
                '$filter' => "OrderType eq 'QT' and OrderNbr eq '{$safe}'",
                '$top' => 1,
            ]);
            $quote = is_array($rows[0] ?? null) ? $rows[0] : [];
        } catch (RuntimeException) {
            // Some IpayV2 SalesOrder contracts do not expose CustomerDetails.
            // Retain compatibility and resolve the customer master below.
            $quote = $this->fetchQuote($quoteNbr);
        }
        if ($quote === []) return [];
        $customerId = self::scalarVal($quote['CustomerID'] ?? $quote['Customer'] ?? null);
        $quote['ResolvedCustomer'] = $customerId ? ($this->fetchCustomer($customerId) ?? []) : [];
        return $quote;
    }

    /**
     * DTC/DTB quotes for the POS customer (CUST101470).
     *
     * @param  string|null  $dateFrom  Inclusive YYYY-MM-DD (null = no lower bound when $dateTo also null → all)
     * @param  string|null  $dateTo    Inclusive YYYY-MM-DD
     * @return list<array<string, mixed>>
     */
    public function fetchDtcCustomerQuotes(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $customer = DtcQuoteService::POS_ORDER_CUSTOMER_ID;
        $filter = "OrderType eq 'QT' and CustomerID eq '{$customer}'";
        if ($dateFrom !== null && $dateTo !== null) {
            $filter .= ' and '.$this->dateRangeClause($dateFrom, $dateTo);
        } elseif ($dateFrom !== null) {
            $filter .= ' and '.$this->dateRangeClause($dateFrom, $dateFrom);
        }
        $params = fn (int $skip, string $expand) => $this->salesOrderListParams([
            '$top' => self::PAGE_SIZE,
            '$skip' => $skip,
            '$filter' => $filter,
            '$expand' => $expand,
        ]);

        try {
            return $this->fetchAllPages(fn (int $skip) => $this->get('SalesOrder', $params($skip, 'Details,CustomerDetails')));
        } catch (RuntimeException $e) {
            // CustomerDetails is not exposed as an expandable child by every
            // IpayV2 SalesOrder contract (Acumatica returns KeyNotFoundException).
            // Details is universally available and is sufficient to import the QT.
            Log::notice('Acumatica SalesOrder.CustomerDetails expansion unavailable; retrying DTC quote import with Details only', [
                'customer_id' => $customer,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $e->getMessage(),
            ]);
            return $this->fetchAllPages(fn (int $skip) => $this->get('SalesOrder', $params($skip, 'Details')));
        }
    }

    /** @return array<string,mixed> */
    public function updatePosOrderCustomerDetails(string $orderId, array $customerDetails): array
    {
        $detail = array_filter([
            'AccountEmail' => !empty($customerDetails['email']) ? ['value'=>(string)$customerDetails['email']] : null,
            'AccountName' => !empty($customerDetails['name']) ? ['value'=>(string)$customerDetails['name']] : null,
            'AddressLine1' => !empty($customerDetails['address_line1']) ? ['value'=>(string)$customerDetails['address_line1']] : null,
            'AddressLine2' => !empty($customerDetails['address_line2']) ? ['value'=>(string)$customerDetails['address_line2']] : null,
            'Phone' => !empty($customerDetails['phone']) ? ['value'=>(string)$customerDetails['phone']] : null,
        ]);
        if ($detail === []) throw new RuntimeException('At least one customer detail is required.');
        return $this->put('PosOrder', ['id'=>$orderId, 'CustomerDetails'=>[$detail]], ['$expand'=>'DocumentDetails,CustomerDetails']);
    }

    /**
     * Create an e-commerce/POS order through the exact IpayV2 PosOrder contract
     * used by the production WooCommerce iPay integration.
     * @param list<array{inventory_id:string, quantity:float|int}> $lines
     * @return array{order_nbr:string, order_id:?string, order_total:float, raw:array<string,mixed>}
     */
    /**
     * @param  ?array{name?: ?string, phone?: ?string, email?: ?string, address_line1?: ?string, address_line2?: ?string}  $shipTo
     */
    public function createPosOrder(string $customerId, array $lines, ?array $customerDetails = null): array
    {
        $details = [];
        foreach ($lines as $line) {
            $inventoryId = trim((string) ($line['inventory_id'] ?? ''));
            $quantity = (float) ($line['quantity'] ?? 0);
            if ($inventoryId !== '' && $quantity > 0) {
                $details[] = [
                    'InventoryID' => ['value' => $inventoryId],
                    'Quantity' => ['value' => $quantity],
                ];
            }
        }
        if ($details === []) throw new RuntimeException('Cannot create PosOrder without valid lines.');
        $payload = [
            'Customer' => ['value' => $customerId],
            'DocumentDetails' => $details,
        ];
        if ($customerDetails !== null) {
            $detail = array_filter([
                'AccountEmail' => !empty($customerDetails['email']) ? ['value' => (string)$customerDetails['email']] : null,
                'AccountName' => !empty($customerDetails['name']) ? ['value' => (string)$customerDetails['name']] : null,
                'AddressLine1' => !empty($customerDetails['address_line1']) ? ['value' => (string)$customerDetails['address_line1']] : null,
                'AddressLine2' => !empty($customerDetails['address_line2']) ? ['value' => (string)$customerDetails['address_line2']] : null,
                'Phone' => !empty($customerDetails['phone']) ? ['value' => (string)$customerDetails['phone']] : null,
            ]);
            if ($detail !== []) $payload['CustomerDetails'] = [$detail];
        }
        $response = $this->put('PosOrder', $payload, ['$expand' => 'DocumentDetails,CustomerDetails']);
        $orderNbr = self::scalarVal($response['OrderNbr'] ?? null);
        if (! $orderNbr) throw new RuntimeException('Acumatica PosOrder response did not contain OrderNbr.');
        return [
            'order_nbr' => (string) $orderNbr,
            'order_id' => isset($response['id']) ? (string) $response['id'] : null,
            'order_total' => (float) (self::val($response['OrderTotal'] ?? null) ?? 0),
            'raw' => $response,
        ];
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

    public function fetchAllSalesOrdersForCustomerByDateRange(
        string $customerId,
        string $dateFrom,
        string $dateTo,
        ?callable $onProgress = null,
    ): array {
        return $this->fetchAllPages(
            fn (int $skip) => $this->fetchSalesOrdersForCustomerByDateRange($customerId, $dateFrom, $dateTo, $skip),
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

        // Simple OrderNbr eq filters on SalesOrder are supported on IpayV2.
        // Do not confuse this with SalesInvoice Details/any(...) filtering —
        // that path is gated separately (see fetchSalesInvoicesForSalesOrders).
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

    /**
     * Fetch released, non-voided invoices that contain lines originating from
     * the supplied sales orders. IpayV2 exposes the origin as OrderNbr (or
     * SalesOrderNbr on some endpoint revisions) on SalesInvoice.Details.
     *
     * IpayV2 22.200.001 rejects Details/any(...) with an OData binder
     * KeyNotFoundException. Gate with ACUMATICA_SALES_INVOICE_DETAIL_FILTER_SUPPORTED
     * so scheduler runs do not hammer the known-unsupported query. Opt in only
     * after confirming the endpoint revision accepts the filter.
     *
     * @param  list<string>  $orderNbrs
     * @return list<array<string, mixed>>
     */
    public function fetchSalesInvoicesForSalesOrders(array $orderNbrs, ?callable $onProgress = null): array
    {
        $orderNbrs = array_values(array_unique(array_filter(array_map(
            static fn ($value) => is_string($value) ? strtoupper(trim($value)) : '',
            $orderNbrs,
        ))));

        if ($orderNbrs === []) {
            return [];
        }

        // IpayV2 22.200.001 cannot filter SalesInvoice by Details/any(OrderNbr).
        // CompletedOrderInvoiceReconciliationService treats this as a soft
        // "unavailable" result; throwing here avoids noisy 500s from Acumatica.
        if (! (bool) config('services.acumatica.sales_invoice_detail_filter_supported', false)) {
            throw new RuntimeException(
                'SalesInvoice detail filtering is unavailable on this Acumatica endpoint revision.',
            );
        }

        $all = [];
        foreach (array_chunk($orderNbrs, 10) as $chunk) {
            $originFilter = implode(' or ', array_map(
                fn (string $orderNbr) => "Details/any(d: d/OrderNbr eq '".$this->quoteODataValue($orderNbr)."')",
                $chunk,
            ));

            $all = array_merge($all, $this->fetchAllPages(
                fn (int $skip) => $this->get('SalesInvoice', [
                    '$top' => self::PAGE_SIZE,
                    '$skip' => $skip,
                    '$expand' => 'Details',
                    '$filter' => "Released eq true and Voided ne true and ({$originFilter})",
                ]),
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
            '$filter' => $this->salesOrderTypeClause()." and Date ge datetimeoffset'{$fromIso}' and Date le datetimeoffset'{$toIso}'",
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

    /**
     * Page of stock items for warehouse balance sync.
     *
     * Never filters by DefaultWarehouseID — secondary sites (TPFGS, MSA, …) only appear on
     * WarehouseDetails. Always expands WarehouseDetails when the entity supports it.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchStockItemsForWarehouseBalances(int $skip = 0, int $top = self::INVENTORY_PAGE_SIZE): array
    {
        $top = min($top, self::MAX_CHUNK_SIZE);

        $queryVariants = [
            [
                '$top' => $top,
                '$skip' => $skip,
                '$expand' => 'WarehouseDetails',
                '$filter' => "ItemStatus eq 'Active'",
            ],
            [
                '$top' => $top,
                '$skip' => $skip,
                '$expand' => 'WarehouseDetails',
            ],
            [
                '$top' => $top,
                '$skip' => $skip,
                '$filter' => "ItemStatus eq 'Active'",
            ],
            [
                '$top' => $top,
                '$skip' => $skip,
            ],
        ];

        foreach (['StockItem', 'stockItem'] as $entity) {
            foreach ($queryVariants as $query) {
                try {
                    return $this->getWithRetry($entity, $query);
                } catch (RuntimeException) {
                    continue;
                }
            }
        }

        if (! $this->isEntityUnavailable('InventoryItem')) {
            foreach ($queryVariants as $query) {
                try {
                    return $this->getWithRetry('InventoryItem', $query);
                } catch (RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'Entity ') && str_contains($e->getMessage(), 'not found')) {
                        $this->markEntityUnavailable('InventoryItem', $e->getMessage());
                    }
                    continue;
                }
            }
        }

        throw new RuntimeException('Acumatica stock item endpoint is unavailable for warehouse balance sync.');
    }

    /**
     * @param  bool  $matchDefaultWarehouseOnly  When true (legacy full catalog sync), OData may
     *                                           filter DefaultWarehouseID. Prefer
     *                                           fetchStockItemsForWarehouseBalances() for stocks.
     */
    public function fetchActiveInventoryItems(
        int $skip = 0,
        int $top = self::INVENTORY_PAGE_SIZE,
        ?string $warehouseId = null,
        ?string $itemClass = null,
        bool $matchDefaultWarehouseOnly = true,
    ): array {
        $top = min($top, self::MAX_CHUNK_SIZE);

        // Stocks-only multi-warehouse path: dedicated fetcher (no DefaultWarehouseID filter).
        if ($warehouseId !== null && ! $matchDefaultWarehouseOnly && $itemClass === null) {
            return $this->fetchStockItemsForWarehouseBalances($skip, $top);
        }

        $filterParts = ["ItemStatus eq 'Active'"];
        if ($warehouseId !== null && $matchDefaultWarehouseOnly) {
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

        // IpayV2 does not expose InventoryItem — skip after first failure (cached).
        if (! $this->isEntityUnavailable('InventoryItem')) {
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

        return $this->fetchStockItems($skip, $top, $warehouseId, $itemClass, $matchDefaultWarehouseOnly);
    }

    public function fetchStockItems(
        int $skip = 0,
        int $top = self::INVENTORY_PAGE_SIZE,
        ?string $warehouseId = null,
        ?string $itemClass = null,
        bool $matchDefaultWarehouseOnly = true,
    ): array
    {
        if ($warehouseId !== null && ! $matchDefaultWarehouseOnly && $itemClass === null) {
            return $this->fetchStockItemsForWarehouseBalances($skip, $top);
        }

        $query = [
            '$top'  => min($top, self::MAX_CHUNK_SIZE),
            '$skip' => $skip,
            '$expand' => 'WarehouseDetails',
        ];

        $filterParts = [];
        if ($warehouseId !== null && $matchDefaultWarehouseOnly) {
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
