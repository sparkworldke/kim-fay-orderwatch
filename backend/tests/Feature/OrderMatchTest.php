<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\MatchLog;
use App\Models\MatchPrediction;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\OrderMatch\OrderMatchAcceptService;
use App\Services\OrderMatch\OrderMatchNotificationService;
use App\Services\OrderMatch\OrderMatchPoNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_normalizer_strips_invalid_chars(): void
    {
        $n = new OrderMatchPoNormalizer;
        $this->assertSame('PO847', $n->normalise('  po 847 '));
        $this->assertSame('PO-847', $n->normalise('PO-847'));
        $this->assertSame('PO847', $n->normalise('PO847'));
    }

    public function test_accept_writes_append_only_match_log(): void
    {
        $user = User::factory()->create();
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO100',
            'customer_order'      => 'PO-1',
            'order_total'         => 1000,
        ]);
        $email = Email::create([
            'mailbox_account_id' => MailboxAccount::create([
                'email' => 'mb@test.com', 'access_token_encrypted' => 'x', 'refresh_token_encrypted' => 'y', 'status' => 'connected',
            ])->id,
            'message_id'  => 'msg-1',
            'canonical_po' => 'PO-1',
            'match_status' => 'pending',
        ]);
        MatchPrediction::create([
            'email_id' => $email->id, 'order_id' => $order->id, 'order_nbr' => 'SO100',
            'confidence' => 0.98, 'match_type' => 'exact', 'is_top_prediction' => true,
        ]);

        $result = app(OrderMatchAcceptService::class)->accept($email, $user->id);

        $this->assertSame('accepted', $result['status']);
        $this->assertDatabaseHas('match_log', ['email_id' => $email->id, 'status' => 'accepted', 'order_nbr' => 'SO100']);
        $this->expectException(\LogicException::class);
        MatchLog::first()->update(['status' => 'hacked']);
    }

    public function test_duplicate_accept_blocked_without_canonical(): void
    {
        $user = User::factory()->create();
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO200', 'customer_order' => 'PO-2', 'order_total' => 500,
        ]);
        $email = Email::create([
            'mailbox_account_id' => MailboxAccount::create([
                'email' => 'mb2@test.com', 'access_token_encrypted' => 'x', 'refresh_token_encrypted' => 'y', 'status' => 'connected',
            ])->id,
            'message_id'     => 'msg-2',
            'duplicate_flag' => 'duplicate',
            'match_status'   => 'pending',
        ]);
        MatchPrediction::create([
            'email_id' => $email->id, 'order_id' => $order->id, 'order_nbr' => 'SO200',
            'confidence' => 0.99, 'is_top_prediction' => true,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(OrderMatchAcceptService::class)->accept($email, $user->id);
    }

    public function test_notification_r5_skips_below_threshold(): void
    {
        NotificationRule::create([
            'rule_key' => 'R5', 'label' => 'Queue', 'channels' => ['email'], 'is_enabled' => true,
        ]);

        $result = app(OrderMatchNotificationService::class)->evaluateAll();

        $this->assertSame('below_threshold', $result['R5']['skipped'] ?? null);
    }

    public function test_order_match_routes_require_auth(): void
    {
        $this->getJson('/api/order-match/queue')->assertUnauthorized();
    }

    public function test_queue_endpoint_returns_groups(): void
    {
        $user = User::factory()->create(['role' => 'Customer Service Agent', 'is_active' => true]);
        $account = MailboxAccount::create([
            'email' => 'q@test.com', 'access_token_encrypted' => 'x', 'refresh_token_encrypted' => 'y', 'status' => 'connected',
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id, 'external_folder_id' => 'f1', 'display_name' => 'Orders',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'mailbox_folder_id'  => $folder->id,
            'message_id'         => 'msg-q',
            'po_extraction_attempted' => true,
            'match_status'       => 'pending',
            'canonical_po'       => 'PO-99',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/order-match/queue')
            ->assertOk()
            ->assertJsonStructure(['groups', 'total']);
    }
}
