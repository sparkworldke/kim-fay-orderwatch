<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_feed_lists_grouped_metrics(): void
    {
        $user = User::factory()->create();
        $mailbox = MailboxAccount::create([
            'email'                    => 'orders@kimfay.com',
            'access_token_encrypted'   => 'x',
            'refresh_token_encrypted'  => 'y',
            'status'                   => 'connected',
        ]);

        $main = AcumaticaCustomer::create([
            'acumatica_id'    => 'CUST-MAIN',
            'name'            => 'Naivas Supermarket',
            'is_main_account' => true,
            'status'          => 'Active',
        ]);

        AcumaticaCustomer::create([
            'acumatica_id'        => 'CUST-BR1',
            'name'                => 'Naivas Supermarket - Thika',
            'parent_acumatica_id' => 'CUST-MAIN',
            'is_main_account'     => false,
            'status'              => 'Active',
        ]);

        $orderMain = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-MAIN-1',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-MAIN',
            'customer_name'         => 'Naivas Supermarket',
            'status'                => 'Completed',
            'order_date'            => now()->startOfMonth(),
            'completed_at'          => now()->startOfMonth()->addHours(36),
            'match_status'          => 'matched',
        ]);

        $orderBranch = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-BR-1',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-BR1',
            'customer_name'         => 'Naivas Supermarket - Thika',
            'status'                => 'Completed',
            'order_date'            => now()->startOfMonth(),
            'completed_at'          => now()->startOfMonth()->addHours(48),
            'match_status'          => 'matched_discrepancies',
        ]);

        Email::create([
            'mailbox_account_id'  => $mailbox->id,
            'message_id'          => 'msg-1',
            'subject'             => 'PO 12345',
            'from_email'          => 'orders@naivas.co.ke',
            'received_at'         => now()->startOfMonth(),
            'matched_order_id'    => $orderBranch->id,
            'match_classification'=> 'matched_discrepancies',
            'match_conflicts'     => [
                [
                    'field'           => 'total',
                    'email_value'     => '1000',
                    'acumatica_value' => '1100',
                    'amount_delta'    => '100',
                    'reason'          => 'total_mismatch',
                ],
            ],
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id'      => $orderMain->id,
            'order_nbr'           => 'SO-MAIN-1',
            'customer_acumatica_id' => 'CUST-MAIN',
            'status'              => 'Completed',
            'total_ordered_qty'   => 10,
            'total_shipped_qty'   => 8,
            'fill_rate_pct'       => 80,
            'fill_rate_status'    => 'at_risk',
            'computed_at'         => now(),
        ]);

        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $response = $this->actingAs($user)
            ->getJson("/api/customer-feed?date_from={$from}&date_to={$to}")
            ->assertOk();

        $response->assertJsonPath('groups.0.group_key', 'CUST-MAIN');
        $response->assertJsonPath('groups.0.display_name', 'Naivas Supermarket');
        $response->assertJsonPath('groups.0.is_grouped', true);
        $response->assertJsonPath('groups.0.order_count', 2);
        $response->assertJsonPath('groups.0.matched_orders', 2);
        $response->assertJsonPath('groups.0.email_count', 1);
    }

    public function test_customer_feed_insights_returns_issue_breakdown(): void
    {
        $user = User::factory()->create();
        $mailbox = MailboxAccount::create([
            'email'                    => 'orders@kimfay.com',
            'access_token_encrypted'   => 'x',
            'refresh_token_encrypted'  => 'y',
            'status'                   => 'connected',
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-INS-1',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-X',
            'customer_name'         => 'Acme Retail',
            'status'                => 'Open',
            'order_date'            => now(),
            'match_status'          => 'matched_discrepancies',
        ]);

        Email::create([
            'mailbox_account_id'   => $mailbox->id,
            'message_id'           => 'msg-ins',
            'subject'              => 'PO 999',
            'from_email'           => 'buyer@acme.test',
            'received_at'          => now(),
            'matched_order_id'     => $order->id,
            'match_classification' => 'matched_discrepancies',
            'match_conflicts'      => [
                [
                    'field'           => 'quantity:SKU-1',
                    'email_value'     => '10',
                    'acumatica_value' => '8',
                    'reason'          => 'qty_mismatch',
                ],
            ],
        ]);

        $from = now()->toDateString();
        $to   = now()->toDateString();

        $this->actingAs($user)
            ->getJson("/api/customer-feed/CUST-X/insights?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonPath('display_name', 'Acme Retail')
            ->assertJsonFragment(['type' => 'quantity', 'count' => 1]);
    }

    public function test_customer_feed_requires_auth(): void
    {
        $this->getJson('/api/customer-feed')->assertUnauthorized();
    }
}