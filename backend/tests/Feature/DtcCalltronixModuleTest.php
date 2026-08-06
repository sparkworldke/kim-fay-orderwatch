<?php
namespace Tests\Feature;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\DtcPrice;
use App\Models\DtcPriceList;
use App\Models\DtcQuote;
use App\Models\DtcSyncLog;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use App\Services\Dtc\DtcPriceSyncService;
use App\Services\Dtc\DtcQuoteService;
use App\Services\Dtc\DtcQuoteImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;
class DtcCalltronixModuleTest extends TestCase
{
    use RefreshDatabase;
    public function test_customers_endpoint_only_returns_dtcaccount_customers(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);
        AcumaticaCustomer::create(['acumatica_id'=>'DTC1','name'=>'DTC Hotel','customer_class'=>'DTCACCOUNT','status'=>'Active']);
        AcumaticaCustomer::create(['acumatica_id'=>'KP1','name'=>'KP Hotel','customer_class'=>'KP','status'=>'Active']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/kp/dtc-calltronix/customers')->assertOk()->assertJsonPath('total',1)->assertJsonFragment(['acumatica_id'=>'DTC1'])->assertJsonMissing(['acumatica_id'=>'KP1']);
    }
    public function test_fixed_calltronix_customer_is_visible_even_when_not_dtcaccount_class(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);
        AcumaticaCustomer::create(['acumatica_id'=>'CUST101470','name'=>'DTB - Direct to Business','customer_class'=>'CSCONSUMER','status'=>'Active']);
        DtcQuote::create(['public_ref'=>'ACQ-QT1','acumatica_quote_nbr'=>'QT1','customer_acumatica_id'=>'CUST101470','customer_name'=>'DTB - Direct to Business','status'=>'submitted','quoted_total'=>100,'created_by'=>$admin->id]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/kp/dtc-calltronix/quotes')->assertOk()->assertJsonPath('total',1)->assertJsonFragment(['acumatica_quote_nbr'=>'QT1']);
        $this->getJson('/api/kp/dtc-calltronix/customers')->assertOk()->assertJsonFragment(['acumatica_id'=>'CUST101470']);
    }
    public function test_draft_uses_locked_effective_price_and_snapshots_customer(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);$this->seedCatalog();
        $service=new DtcQuoteService(Mockery::mock(AcumaticaClient::class));
        $quote=$service->saveDraft(['customer_acumatica_id'=>'DTC1','description'=>'Hotel proposal','lines'=>[['inventory_id'=>'SKU1','quantity'=>2]]],$admin);
        $this->assertSame('DTC Hotel',$quote->customer_name);$this->assertSame('200.00',$quote->quoted_total);$this->assertSame('100.000000',$quote->lines->first()->unit_price);
    }
    public function test_submit_creates_qt_and_conversion_is_idempotent(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);$this->seedCatalog();
        $client=Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('createSalesOrder')->once()->withArgs(fn($customer,$lines,$ref,$description,$type,$zeroPrice)=>$customer==='DTC1'&&$type==='QT'&&$zeroPrice===false&&$lines[0]['unit_price']===100.0)->andReturn(['order_nbr'=>'QT0001','order_id'=>'qid','raw'=>['OrderNbr'=>['value'=>'QT0001']]]);
        $client->shouldReceive('fetchQuoteWithCustomerDetails')->once()->with('QT0001')->andReturn(['CustomerID'=>['value'=>'DTC1'],'Details'=>[],'ResolvedCustomer'=>['CustomerName'=>['value'=>'DTC Hotel'],'Email'=>['value'=>'buyer@hotel.test'],'Phone1'=>['value'=>'0700000000'],'AddressLine1'=>['value'=>'Hotel Road']]]);
        $client->shouldReceive('createPosOrder')->once()->withArgs(fn($customer,$lines,$details)=>$customer==='CUST101470'&&$lines===[['inventory_id'=>'SKU1','quantity'=>2.0]]&&$details['name']==='DTC Hotel'&&$details['email']==='buyer@hotel.test'&&$details['phone']==='0700000000'&&$details['address_line1']==='Hotel Road')->andReturn(['order_nbr'=>'SO0001','order_id'=>'soid','order_total'=>210.0,'raw'=>['OrderNbr'=>['value'=>'SO0001']]]);
        $service=new DtcQuoteService($client);$quote=$service->saveDraft(['customer_acumatica_id'=>'DTC1','lines'=>[['inventory_id'=>'SKU1','quantity'=>2]]],$admin);$quote=$service->submit($quote);$this->assertSame('QT0001',$quote->acumatica_quote_nbr);
        $first=$service->convert($quote,$admin);$second=$service->convert($first,$admin);$this->assertSame('SO0001',$second->conversion->acumatica_order_nbr);$this->assertSame('10.00',$second->conversion->price_variance);$this->assertSame(1,$second->conversion->attempt_count);
    }
    public function test_imports_fixed_customer_quotes_by_date_and_upserts_without_duplicates(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);
        AcumaticaCustomer::create(['acumatica_id'=>'CUST101470','name'=>'Calltronix Account','customer_class'=>'DTCACCOUNT','status'=>'Active']);
        $raw=['id'=>'qid','OrderNbr'=>['value'=>'QT9001'],'OrderType'=>['value'=>'QT'],'CustomerID'=>['value'=>'CUST101470'],'Date'=>['value'=>'2026-07-16T10:00:00+00:00'],'OrderTotal'=>['value'=>250],'CurrencyID'=>['value'=>'KES'],'Details'=>[['InventoryID'=>['value'=>'SKU1'],'OrderQty'=>['value'=>2],'UnitPrice'=>['value'=>125],'UOM'=>['value'=>'PIECE']]]];
        $client=Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchDtcCustomerQuotes')->twice()->with('2026-07-16', '2026-07-16')->andReturn([$raw]);
        $service=new DtcQuoteImportService($client);
        $first=$service->import('2026-07-16', '2026-07-16', $admin);
        $second=$service->import('2026-07-16', '2026-07-16', $admin);
        $this->assertSame(1,$first['created']);$this->assertSame(1,$second['updated']);$this->assertSame(1,DtcQuote::where('acumatica_quote_nbr','QT9001')->count());$this->assertSame('250.00',DtcQuote::first()->quoted_total);$this->assertSame('SKU1',DtcQuote::first()->lines()->first()->inventory_id);
    }
    public function test_conversion_does_not_require_current_dtc_price(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);$this->seedCatalog();
        $client=Mockery::mock(AcumaticaClient::class);
        $service=new DtcQuoteService($client);
        $quote=$service->saveDraft(['customer_acumatica_id'=>'DTC1','lines'=>[['inventory_id'=>'SKU1','quantity'=>2]]],$admin);
        $quote->update(['acumatica_quote_nbr'=>'QT-NOPRICE','status'=>'submitted']);
        DtcPrice::query()->delete();
        $client->shouldReceive('fetchQuoteWithCustomerDetails')->once()->andReturn(['CustomerID'=>['value'=>'DTC1'],'Details'=>[['InventoryID'=>['value'=>'SKU1'],'OrderQty'=>['value'=>2]]],'ResolvedCustomer'=>[]]);
        $client->shouldReceive('createPosOrder')->once()->withArgs(fn($customer,$lines,$details)=>$customer==='CUST101470'&&$lines===[['inventory_id'=>'SKU1','quantity'=>2.0]]&&$details['name']==='DTC Hotel')->andReturn(['order_nbr'=>'SO-NOPRICE','order_id'=>'id','order_total'=>250,'raw'=>['OrderNbr'=>['value'=>'SO-NOPRICE']]]);
        $converted=$service->convert($quote,$admin);
        $this->assertSame('SO-NOPRICE',$converted->conversion->acumatica_order_nbr);
    }
    public function test_sync_products_seeds_dtc_price_list_from_inventory(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU1',
            'description' => 'Dispenser',
            'item_status' => 'Active',
            'qty_available' => 10,
            'qty_on_hand' => 10,
            'default_warehouse_id' => 'FGS',
            'default_uom' => 'PIECE',
            'sales_price' => 150,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-MSA',
            'description' => 'Other warehouse',
            'item_status' => 'Active',
            'qty_available' => 5,
            'default_warehouse_id' => 'MSA',
            'sales_price' => 80,
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-INACTIVE',
            'description' => 'Gone',
            'item_status' => 'Inactive',
            'qty_available' => 0,
            'default_warehouse_id' => 'FGS',
            'sales_price' => 99,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $service = new DtcPriceSyncService($client);
        $result = $service->syncProducts($admin);

        $this->assertSame(1, $result['products']);
        $row = DtcPriceList::where('inventory_id', 'SKU1')->first();
        $this->assertNotNull($row);
        $this->assertSame('150.000000', $row->dtc_price);
        $this->assertTrue($row->in_catalog);
        $this->assertSame('FGS', $row->default_warehouse_id);
        $this->assertNull(DtcPriceList::where('inventory_id', 'SKU-MSA')->first());
        $this->assertNull(DtcPriceList::where('inventory_id', 'SKU-INACTIVE')->first());
    }

    public function test_price_list_supports_multi_warehouse_and_search(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        DtcPriceList::create([
            'inventory_id' => 'DTC-1',
            'description' => 'DTC Soap',
            'uom' => 'PIECE',
            'dtc_price' => 50,
            'price_code' => 'DTCACCOUNT',
            'default_warehouse_id' => 'DTC',
            'qty_available' => 3,
            'in_catalog' => true,
        ]);
        DtcPriceList::create([
            'inventory_id' => 'FGS-1',
            'description' => 'FGS Cleaner',
            'uom' => 'PIECE',
            'dtc_price' => 80,
            'price_code' => 'DTCACCOUNT',
            'default_warehouse_id' => 'FGS',
            'qty_available' => 5,
            'in_catalog' => true,
        ]);
        DtcPriceList::create([
            'inventory_id' => 'MSA-1',
            'description' => 'MSA Only',
            'uom' => 'PIECE',
            'dtc_price' => 10,
            'price_code' => 'DTCACCOUNT',
            'default_warehouse_id' => 'MSA',
            'qty_available' => 9,
            'in_catalog' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/kp/dtc-calltronix/prices?warehouses=DTC,FGS&priced=yes&stock=in_stock')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['inventory_id' => 'DTC-1'])
            ->assertJsonFragment(['inventory_id' => 'FGS-1'])
            ->assertJsonMissing(['inventory_id' => 'MSA-1']);

        $this->getJson('/api/kp/dtc-calltronix/prices?q=Cleaner')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['inventory_id' => 'FGS-1']);
    }

    public function test_sync_prices_put_matches_inventory_and_sets_dtc_price(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU1',
            'description' => 'Dispenser',
            'item_status' => 'Active',
            'qty_available' => 10,
            'default_warehouse_id' => 'FGS',
            'default_uom' => 'PIECE',
            'sales_price' => 100,
        ]);
        DtcPriceList::create([
            'inventory_id' => 'SKU1',
            'description' => 'Dispenser',
            'uom' => 'PIECE',
            'default_price' => 100,
            'dtc_price' => 100,
            'price_code' => 'DTCACCOUNT',
            'default_warehouse_id' => 'FGS',
            'in_catalog' => true,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchDtcPrices')->once()->andReturn([
            [
                'InventoryID' => ['value' => 'SKU1'],
                'Price' => ['value' => 275],
                'UOM' => ['value' => 'PIECE'],
                'PriceCode' => ['value' => 'DTCACCOUNT'],
                'CurrencyID' => ['value' => 'KES'],
                'Description' => ['value' => 'Dispenser'],
            ],
            [
                'InventoryID' => ['value' => 'OTHER'],
                'Price' => ['value' => 50],
                'UOM' => ['value' => 'PIECE'],
                'PriceCode' => ['value' => 'DTCACCOUNT'],
            ],
        ]);

        $service = new DtcPriceSyncService($client);
        $result = $service->syncPrices($admin);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertSame('sales_prices_inquiry_put', $result['price_source']);
        $this->assertSame('275.000000', DtcPriceList::where('inventory_id', 'SKU1')->value('dtc_price'));
        $this->assertSame(275.0, (float) DtcPrice::where('inventory_id', 'SKU1')->value('price'));
    }

    public function test_price_list_endpoint_reads_dtc_price_list(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        DtcPriceList::create([
            'inventory_id' => 'SKU1',
            'description' => 'Dispenser',
            'uom' => 'PIECE',
            'dtc_price' => 120,
            'default_price' => 100,
            'price_code' => 'DTCACCOUNT',
            'qty_available' => 5,
            'in_catalog' => true,
            'brand' => 'Airoma',
            'taxation' => 'TAXABLE',
        ]);
        // Zero-price catalog row must still list (FGS DefaultPrice often 0 until DTCACCOUNT applied).
        DtcPriceList::create([
            'inventory_id' => 'SKU-ZERO',
            'description' => 'No price yet',
            'uom' => 'PIECE',
            'dtc_price' => null,
            'default_price' => 0,
            'price_code' => 'DTCACCOUNT',
            'in_catalog' => true,
        ]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/kp/dtc-calltronix/prices')
            ->assertOk()
            ->assertJsonPath('total', 2)
            // TAXABLE → price incl. 16% VAT (120 * 1.16 = 139.2)
            ->assertJsonFragment([
                'inventory_id' => 'SKU1',
                'price_ex_vat' => '120.000000',
                'price' => '139.200000',
                'is_taxable' => true,
            ])
            ->assertJsonFragment(['inventory_id' => 'SKU-ZERO', 'price' => '0.000000']);

        $this->getJson('/api/kp/dtc-calltronix/prices?brand=Airoma')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['inventory_id' => 'SKU1']);
    }

    public function test_excel_import_matches_inventory_id_and_sets_taxation(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'AIROM0001',
            'description' => 'Airoma Air Freshener Citrus Dazzle 300ml',
            'item_status' => 'Active',
            'brand' => 'Airoma',
            'default_warehouse_id' => 'FGS',
            'default_uom' => 'CASE',
            'qty_available' => 12,
        ]);
        DtcPriceList::create([
            'inventory_id' => 'AIROM0001',
            'description' => 'Airoma Air Freshener Citrus Dazzle 300ml',
            'uom' => 'CASE',
            'price_code' => 'DTCACCOUNT',
            'brand' => 'Airoma',
            'in_catalog' => true,
        ]);

        $csv = implode("\n", [
            'Price Type,Price Code,Inventory ID,Description,UOM,Warehouse,Price,Tax Category,Effective Date,Expiration Date,Tax,Promotion,Break Qty,Currency',
            'Customer Price Class,DTCACCOUNT,AIROM0001,Airoma Air Freshener Citrus Dazzle 300ml,CASE,,2362.50000,TAXABLE,22/12/2023,,,False,0.000000,KES',
            'Customer Price Class,DTCACCOUNT,UNKNOWN99,Unknown product,PIECE,,99.00,TAXABLE,01/01/2024,,,False,0,KES',
        ]);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dtc-prices-test.csv';
        file_put_contents($path, $csv);

        Sanctum::actingAs($admin);
        $this->post('/api/kp/dtc-calltronix/prices/import-excel', [
            'file' => new \Illuminate\Http\UploadedFile($path, 'dtc-prices-test.csv', 'text/csv', null, true),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('unmatched', 1);

        $log = DtcSyncLog::where('sync_type', 'price_excel_import')->latest('id')->firstOrFail();
        $this->assertSame('completed', $log->status);
        $this->assertSame(1, $log->progress['updated']);
        $this->assertSame(1, $log->progress['created']);
        $this->assertSame(1, $log->progress['unmatched']);

        $row = DtcPriceList::where('inventory_id', 'AIROM0001')->first();
        $this->assertNotNull($row);
        $this->assertSame('2362.500000', $row->dtc_price);
        $this->assertSame('TAXABLE', $row->taxation);
        $this->assertSame('excel', $row->source);
        $this->assertSame('Airoma', $row->brand);
        $this->assertSame('2023-12-22', $row->effective_date?->toDateString());
        $this->assertTrue($row->isTaxable());
        // 2362.50 * 1.16 = 2740.50
        $this->assertEqualsWithDelta(2740.5, $row->effectivePrice(), 0.001);
        $this->assertEqualsWithDelta(377.999999, $row->vatAmount(), 0.01);

        $this->getJson('/api/kp/dtc-calltronix/prices?q=AIROM0001')
            ->assertOk()
            ->assertJsonPath('data.0.is_taxable', true)
            ->assertJsonPath('data.0.price_ex_vat', '2362.500000')
            ->assertJsonPath('data.0.price', '2740.500000');

        @unlink($path);
    }

    public function test_non_taxable_price_has_no_vat(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        DtcPriceList::create([
            'inventory_id' => 'EXEMPT1',
            'description' => 'Exempt item',
            'uom' => 'PIECE',
            'dtc_price' => 100,
            'taxation' => 'EXEMPT',
            'price_code' => 'DTCACCOUNT',
            'in_catalog' => true,
        ]);
        $row = DtcPriceList::where('inventory_id', 'EXEMPT1')->first();
        $this->assertFalse($row->isTaxable());
        $this->assertSame(100.0, $row->effectivePrice());
        $this->assertSame(0.0, $row->vatAmount());
    }

    public function test_filtered_price_list_pdf_uses_company_letterhead_config(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        DtcPriceList::create([
            'inventory_id' => 'PDF-SKU', 'description' => 'PDF Product', 'uom' => 'PIECE',
            'dtc_price' => 125, 'price_code' => 'DTCACCOUNT', 'brand' => 'PDF Brand',
            'taxation' => 'NON-TAXABLE', 'qty_available' => 5, 'in_catalog' => true,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->get('/api/kp/dtc-calltronix/prices/export.pdf?brand=PDF%20Brand&stock=in_stock');
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertGreaterThan(1000, strlen($response->getContent()));
        $this->assertSame('Kim-Fay East Africa Limited', config('company.legal_name'));
        $this->assertSame('+254777777047', config('company.whatsapp'));
        $this->assertSame('customercare@kimfay.com', config('company.email'));
    }

    private function seedCatalog(): void
    {
        AcumaticaCustomer::create(['acumatica_id'=>'DTC1','name'=>'DTC Hotel','customer_class'=>'DTCACCOUNT','status'=>'Active']);
        AcumaticaInventoryItem::create(['inventory_id'=>'SKU1','description'=>'Dispenser','item_status'=>'Active','qty_available'=>10,'qty_on_hand'=>10,'default_warehouse_id'=>'FGS']);
        DtcPrice::create(['inventory_id'=>'SKU1','description'=>'Dispenser','price_code'=>'DTCACCOUNT','uom'=>'PIECE','price'=>100,'currency_id'=>'KES','break_qty'=>0,'effective_date'=>now()->subDay()->toDateString(),'synced_at'=>now()]);
        DtcPriceList::create(['inventory_id'=>'SKU1','description'=>'Dispenser','uom'=>'PIECE','dtc_price'=>100,'default_price'=>100,'price_code'=>'DTCACCOUNT','currency_id'=>'KES','default_warehouse_id'=>'FGS','in_catalog'=>true,'qty_available'=>10]);
    }
}
