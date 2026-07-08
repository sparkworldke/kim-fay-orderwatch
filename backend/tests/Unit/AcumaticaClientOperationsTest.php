<?php

namespace Tests\Unit;

use App\Models\AcumaticaConfig;
use App\Services\Admin\AcumaticaClient;

use App\Services\Admin\EncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcumaticaClientOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('acumatica_access_token', 'test-token');

        AcumaticaConfig::create([
            'base_url'                => 'https://kimfay.acumatica.com',
            'endpoint'                => 'IpayV2',
            'version'                 => '22.200.001',
            'tenant'                  => 'Kim-Fay Limited',
            'grant_type'              => 'password',
            'scope'                   => 'api',
            'username'                => 'test',
            'password_encrypted'      => app(EncryptionService::class)->encrypt('secret'),
            'token_url'               => 'https://kimfay.acumatica.com/identity/connect/token',
            'endpoint_version'        => '22.200.001',
            'health_status'           => 'unchecked',
        ]);
    }

    public function test_fetch_backorders_uses_open_orders_with_details_without_select(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchOpenSalesOrdersForBackorders();

        $url = $this->salesOrderRequestUrl();
        $decoded = urldecode($url);

        $this->assertStringContainsString("OrderType eq 'SO'", $decoded);
        $this->assertStringContainsString("Status ne 'Completed'", $decoded);
        $this->assertStringContainsString('$expand=Details', $url);
        $this->assertStringNotContainsString('$select=', $url);
        $this->assertStringNotContainsString('$expand=DocumentDetails', $url);
    }

    public function test_fetch_orders_for_fill_rate_uses_details_without_select(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchOrdersForFillRatePage('2026-06-01', '2026-06-30');

        $url = $this->salesOrderRequestUrl();

        $this->assertStringContainsString("OrderType eq 'SO'", urldecode($url));
        $this->assertStringContainsString("Status ne 'Completed'", urldecode($url));
        $this->assertStringContainsString('$expand=Details', $url);
        $this->assertStringNotContainsString('$select=', $url);
        $this->assertStringNotContainsString('$expand=DocumentDetails', $url);
    }

    private function salesOrderRequestUrl(): string
    {
        $recorded = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->first(fn (string $url) => str_contains($url, '/SalesOrder/'));

        $this->assertNotNull($recorded, 'No SalesOrder request was recorded.');

        return $recorded;
    }

    public function test_fetch_credit_notes_and_more_filters_non_so_types(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchCreditNotesAndMoreByDateRange('2026-06-19', '2026-06-19');

        $url = $this->salesOrderRequestUrl();
        $decoded = urldecode($url);

        $this->assertStringContainsString("OrderType eq 'QT'", $decoded);
        $this->assertStringContainsString("OrderType eq 'RC'", $decoded);
        $this->assertStringContainsString("OrderType eq 'CM'", $decoded);
        $this->assertStringContainsString("OrderType eq 'PL'", $decoded);
        $this->assertStringContainsString('$expand=Details', $url);
    }

    public function test_fetch_stock_items_does_not_use_select(): void
    {
        Http::fake([
            'https://kimfay.acumatica.com/entity/IpayV2/22.200.001/StockItem/*' => Http::response([]),
        ]);

        $client = app(AcumaticaClient::class);
        $client->fetchStockItems();

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, '/StockItem/')
                && ! str_contains($url, '$select=');
        });
    }

    public function test_fetch_sales_orders_by_date_range_expands_details(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchSalesOrdersByDateRange('2026-06-01', '2026-06-30');

        $url = $this->salesOrderRequestUrl();

        $this->assertStringContainsString('$expand=Details', $url);
        $this->assertStringNotContainsString('$select=', $url);
        $this->assertStringNotContainsString('$expand=DocumentDetails', $url);
        $this->assertStringContainsString("Date ge datetimeoffset'2026-06-01T00:00:00'", urldecode($url));
    }

    public function test_fetch_sales_orders_for_customer_expands_details(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchAllSalesOrdersForCustomer('CUST01');

        $url = $this->salesOrderRequestUrl();

        $this->assertStringContainsString("CustomerID eq 'CUST01'", urldecode($url));
        $this->assertStringContainsString('$expand=Details', $url);
        $this->assertStringNotContainsString('$select=', $url);
    }

    public function test_fetch_customer_categories_does_not_use_select(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchAllCustomerCategories();

        $recorded = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->first(fn (string $url) => str_contains($url, '/CustomerClass/'));

        $this->assertNotNull($recorded);
        $this->assertStringNotContainsString('$select=', $recorded);
    }

    public function test_fetch_shipping_zones_uses_zone_entity_without_select(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchAllShippingZones();

        $recorded = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->first(fn (string $url) => str_contains($url, '/Zone/'));

        $this->assertNotNull($recorded);
        $this->assertStringNotContainsString('$select=', $recorded);
    }

    public function test_fetch_first_by_field_builds_entity_field_filter(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchFirstByField('Route', 'RouteCode', '3G');

        $url = $this->entityRequestUrl('/Route/');

        $this->assertStringContainsString("RouteCode eq '3G'", urldecode($url));
        $this->assertStringContainsString('$top=1', $url);
    }

    public function test_fetch_first_by_field_quotes_odata_values(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(AcumaticaClient::class)->fetchFirstByField('Route', 'RouteName', "Langata's East");

        $url = $this->entityRequestUrl('/Route/');

        $this->assertStringContainsString("RouteName eq 'Langata''s East'", urldecode($url));
    }

    public function test_scalar_val_handles_empty_arrays_and_nested_zone_objects(): void
    {
        $this->assertNull(AcumaticaClient::scalarVal([]));
        $this->assertNull(AcumaticaClient::scalarVal(null));
        $this->assertSame('Z005', AcumaticaClient::scalarVal(['value' => 'Z005']));
        $this->assertSame(
            'Z005',
            AcumaticaClient::scalarVal([
                'ZoneID' => ['value' => 'Z005'],
                'Description' => ['value' => 'Nairobi Zone'],
            ]),
        );
    }

    private function entityRequestUrl(string $entityPath): string
    {
        $recorded = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->first(fn (string $url) => str_contains($url, $entityPath));

        $this->assertNotNull($recorded, "No {$entityPath} request was recorded.");

        return $recorded;
    }
}
