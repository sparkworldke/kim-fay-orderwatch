<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\EmailMatchAttempt;
use App\Models\MailboxAccount;
use App\Models\User;
use App\Services\Email\OrderMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuardedOrderMatchingTest extends TestCase
{
    use RefreshDatabase;

    private OrderMatchingService $matching;
    private MailboxAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matching = app(OrderMatchingService::class);
        $this->mailbox = MailboxAccount::create([
            'email' => 'orders@example.com', 'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'x', 'status' => 'connected',
        ]);
        EmailImportConfig::create([
            'sender_pattern' => '*@buyer.example', 'is_wildcard' => true,
            'display_name' => 'Buyer', 'is_active' => true, 'ai_fallback_enabled' => false,
        ]);
    }

    public function test_exact_unambiguous_po_is_matched_and_audited(): void
    {
        $order = $this->order('SO001', '004512');
        $email = $this->email('PO 004512 attached');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $email->refresh();
        $this->assertSame('004512', $email->extracted_po_number);
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
        $this->assertDatabaseHas('email_match_attempts', ['email_id' => $email->id, 'classification' => 'matched']);
    }

    public function test_multiple_po_candidates_are_never_auto_linked(): void
    {
        $this->order('SO001', '004512');
        $email = $this->email('PO 004512 and PO 004513');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $email->refresh();
        $this->assertSame('needs_review', $email->match_classification);
        $this->assertNull($email->matched_order_id);
        $this->assertContains('multiple_po_candidates', $email->match_reason_codes);
    }

    public function test_explicit_total_conflict_links_but_quarantines(): void
    {
        $order = $this->order('SO001', '004512', ['order_total' => 1000, 'currency_id' => 'KES']);
        $email = $this->email('PO 004512', 'Currency: KES Total: 1250.00');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $email->refresh();
        $this->assertSame('matched_discrepancies', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
        $this->assertSame('matched_discrepancies', $order->fresh()->match_status);
        $this->assertSame('total', $email->match_conflicts[0]['field']);
    }

    public function test_orders_api_returns_match_discrepancy_details(): void
    {
        $user = User::factory()->create();
        $order = $this->order('SO-DISC', '004512', ['order_total' => 1000, 'currency_id' => 'KES']);
        $this->email('PO 004512', 'Currency: KES Total: 1250.00');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $this->actingAs($user)
            ->getJson('/api/orders?match_status=matched_discrepancies')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.matched_po_number', '004512')
            ->assertJsonPath('data.0.match_conflicts.0.field', 'total')
            ->assertJsonPath('data.0.match_conflicts.0.email_value', '1250.00')
            ->assertJsonPath('data.0.match_conflicts.0.acumatica_value', '1000.00');
    }

    public function test_duplicate_customer_order_is_ambiguous_and_never_selects_first(): void
    {
        $this->order('SO001', '004512');
        $this->order('SO002', '004512');
        $email = $this->email('PO 004512');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $this->assertSame('needs_review', $email->fresh()->match_classification);
        $this->assertNull($email->fresh()->matched_order_id);
    }

    public function test_reviewed_record_is_not_overwritten_by_repeated_run(): void
    {
        $this->order('SO001', '004512');
        $email = $this->email('PO 004512');
        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();
        $email->update(['reviewer_decision' => 'approved', 'reviewer_reason' => 'Verified', 'reviewed_at' => now()]);
        $attempts = EmailMatchAttempt::where('email_id', $email->id)->count();

        $this->matching->runOrderMatching();

        $this->assertSame('approved', $email->fresh()->reviewer_decision);
        $this->assertSame($attempts, EmailMatchAttempt::where('email_id', $email->id)->count());
    }

    public function test_ai_or_low_confidence_evidence_can_only_propose_a_match(): void
    {
        $this->order('SO001', '004512');
        $email = $this->email('Order attached');
        $email->update([
            'po_extraction_attempted' => true, 'extracted_po_number' => '004512',
            'po_extraction_method' => 'ai_openai', 'po_extraction_confidence' => 79,
            'match_evidence' => [[
                'po_number' => '004512', 'source' => 'ai_context', 'method' => 'ai_openai',
                'confidence' => 79, 'raw_match' => '004512', 'deterministic' => false,
            ]],
        ]);

        $this->matching->runOrderMatching();

        $this->assertSame('needs_review', $email->fresh()->match_classification);
        $this->assertNull($email->fresh()->matched_order_id);
        $this->assertContains('ai_context_only', $email->fresh()->match_reason_codes);
    }

    public function test_untrusted_thread_only_evidence_requires_review(): void
    {
        $this->order('SO001', '004512');
        $prior = $this->email('PO 004512');
        $prior->update([
            'conversation_id' => 'conversation-1', 'extracted_po_number' => '004512',
            'po_extraction_attempted' => true, 'reviewer_decision' => 'rejected',
        ]);
        $current = $this->email('Re: order update');
        $current->update(['conversation_id' => 'conversation-1', 'po_extraction_attempted' => true]);

        $this->matching->runOrderMatching();

        $this->assertSame('needs_review', $current->fresh()->match_classification);
        $this->assertContains('thread_only_evidence', $current->fresh()->match_reason_codes);
        $this->assertNull($current->fresh()->matched_order_id);
    }

    public function test_sales_order_outside_72_hour_window_is_not_matched(): void
    {
        $this->order('SO001', '004512', ['order_date' => now()->subDays(5)]);
        $email = $this->email('PO 004512 attached');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $email->refresh();
        $this->assertSame('004512', $email->extracted_po_number);
        $this->assertSame('not_matched', $email->match_classification);
        $this->assertNull($email->matched_order_id);
        $this->assertContains('po_not_found_in_acumatica', $email->match_reason_codes);
    }

    public function test_duplicate_customer_order_outside_window_matches_recent_sales_order(): void
    {
        $recent = $this->order('SO001', '004512');
        $this->order('SO002', '004512', ['order_date' => now()->subDays(10)]);
        $email = $this->email('PO 004512');

        $this->matching->runPoExtraction();
        $this->matching->runOrderMatching();

        $email->refresh();
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($recent->id, $email->matched_order_id);
    }

    private function email(string $subject, ?string $body = null): Email
    {
        return Email::create([
            'mailbox_account_id' => $this->mailbox->id, 'message_id' => uniqid('message-', true),
            'subject' => $subject, 'from_email' => 'orders@buyer.example', 'body_content' => $body,
            'received_at' => now(), 'folder' => 'Inbox',
        ]);
    }

    private function order(string $number, string $customerOrder, array $extra = []): AcumaticaSalesOrder
    {
        return AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => $number, 'customer_order' => $customerOrder,
            'order_date' => now(), 'status' => 'Open', ...$extra,
        ]);
    }
}
