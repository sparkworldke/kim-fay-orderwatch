<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\User;
use App\Services\OrderMatch\OrderMatchAiMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTypeFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_kpis_count_sales_orders_only(): void
    {
        $user = User::factory()->create();
        $today = now()->toDateString();

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO1001',
            'order_type'          => 'SO',
            'order_date'          => $today,
            'status'              => 'Open',
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'QT2001',
            'order_type'          => 'QT',
            'order_date'          => $today,
            'status'              => 'Open',
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'RC3001',
            'order_type'          => 'RC',
            'order_date'          => $today,
            'status'              => 'Open',
        ]);

        $this->actingAs($user)
            ->getJson("/api/dashboard/kpis?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('open', 1)
            ->assertJsonPath('open_so', 1)
            ->assertJsonMissingPath('open_qt');
    }

    public function test_so_imports_stats_break_down_by_document_type(): void
    {
        $user = User::factory()->create();
        $today = now()->toDateString();

        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO1', 'order_type' => 'SO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO2', 'order_type' => 'SO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'QT1', 'order_type' => 'QT', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'QO1', 'order_type' => 'QO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'RC1', 'order_type' => 'RC', 'order_date' => $today]);

        $this->actingAs($user)
            ->getJson("/api/so-imports?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonPath('stats.so', 2)
            ->assertJsonPath('stats.qt', 2)
            ->assertJsonPath('stats.rc', 1)
            ->assertJsonPath('stats.successful', 5)
            ->assertJsonPath('stats.in_scope_so', 2);
    }

    public function test_so_imports_can_filter_list_by_order_type(): void
    {
        $user = User::factory()->create();
        $today = now()->toDateString();

        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO9', 'order_type' => 'SO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'QT9', 'order_type' => 'QT', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'QO9', 'order_type' => 'QO', 'order_date' => $today]);

        $this->actingAs($user)
            ->getJson("/api/so-imports?date_from={$today}&date_to={$today}&order_type=QT")
            ->assertOk()
            ->assertJsonCount(2, 'items.data');

        $this->actingAs($user)
            ->getJson("/api/so-imports?date_from={$today}&date_to={$today}&order_type=SO")
            ->assertOk()
            ->assertJsonPath('items.data.0.acumatica_order_nbr', 'SO9')
            ->assertJsonCount(1, 'items.data');
    }

    public function test_so_imports_can_search_by_order_number(): void
    {
        $user = User::factory()->create();
        $today = now()->toDateString();

        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO12345', 'order_type' => 'SO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO99999', 'order_type' => 'SO', 'order_date' => $today]);

        $this->actingAs($user)
            ->getJson("/api/so-imports?date_from={$today}&date_to={$today}&q=12345")
            ->assertOk()
            ->assertJsonPath('items.data.0.acumatica_order_nbr', 'SO12345')
            ->assertJsonCount(1, 'items.data');
    }

    public function test_orders_endpoint_supports_production_date_range_query(): void
    {
        $user = User::factory()->create();

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-RANGE',
            'order_type'          => 'SO',
            'order_date'          => '2026-06-15',
            'status'              => 'Open',
            'match_status'        => 'pending',
        ]);

        $this->actingAs($user)
            ->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-25&order_type=SO&sort=latest&page=1&per_page=50')
            ->assertOk()
            ->assertJsonPath('data.0.acumatica_order_nbr', 'SO-RANGE')
            ->assertJsonCount(1, 'data');
    }

    public function test_orders_endpoint_ignores_non_so_order_type_filter(): void
    {
        $user = User::factory()->create();
        $today = now()->toDateString();

        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO1', 'order_type' => 'SO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'QO-LEGACY', 'order_type' => 'QO', 'order_date' => $today]);

        $this->actingAs($user)
            ->getJson("/api/orders?order_type=QT&date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonPath('data.0.acumatica_order_nbr', 'SO1')
            ->assertJsonCount(1, 'data');
    }

    public function test_credit_notes_more_endpoint_returns_non_so_types(): void
    {
        $user = User::factory()->create();
        $today = now()->toDateString();

        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO1', 'order_type' => 'SO', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'RC1', 'order_type' => 'RC', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'CM1', 'order_type' => 'CM', 'order_date' => $today]);
        AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'PL1', 'order_type' => 'PL', 'order_date' => $today]);

        $this->actingAs($user)
            ->getJson("/api/orders?order_type=CREDIT_NOTES_MORE&date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_order_match_treats_rc_only_po_as_no_match(): void
    {
        $account = MailboxAccount::create([
            'email' => 'match@test.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'f1',
            'display_name' => 'Orders',
        ]);

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'RC900',
            'order_type'          => 'RC',
            'customer_order'      => 'PO-RC-ONLY',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id'  => $account->id,
            'mailbox_folder_id'   => $folder->id,
            'message_id'          => 'msg-rc',
            'canonical_po'        => 'PO-RC-ONLY',
            'po_extraction_confidence' => 95,
            'match_status'        => 'pending',
        ]);

        $prediction = app(OrderMatchAiMatchingService::class)->score($email);

        $this->assertSame(0.0, (float) $prediction->confidence);
        $this->assertSame('no_match', $prediction->match_type);
        $this->assertStringContainsString('excluded_order_type: RC', $prediction->reasoning);
    }
}