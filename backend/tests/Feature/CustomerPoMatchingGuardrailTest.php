<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\MailboxAccount;
use App\Services\Email\OrderMatchingService;
use App\Services\OrderMatch\OrderMatchAiMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPoMatchingGuardrailTest extends TestCase
{
    use RefreshDatabase;

    private MailboxAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = MailboxAccount::create([
            'email' => 'orders@kimfay.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);

        EmailImportConfig::create([
            'sender_pattern' => 'notification@naivas.net',
            'is_wildcard' => false,
            'display_name' => 'Naivas',
            'is_active' => true,
            'ai_fallback_enabled' => false,
        ]);

        EmailImportConfig::create([
            'sender_pattern' => 'kencarrefourorders@maf.ae',
            'is_wildcard' => false,
            'display_name' => 'Carrefour',
            'is_active' => true,
            'ai_fallback_enabled' => false,
        ]);
    }

    public function test_naivas_prefixed_po_matches_acumatica_numeric_customer_order(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-1',
            'order_type'          => 'SO',
            'customer_order'      => '42562296',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id' => $this->mailbox->id,
            'message_id'         => 'naivas-1',
            'subject'            => 'Purchase order Confirmation: P042562296 - KIM-FAY EAST AFRICA LIMITED',
            'from_email'         => 'notification@naivas.net',
            'received_at'        => now(),
            'folder'             => 'Naivas POs',
        ]);

        app(OrderMatchingService::class)->runPoExtraction();
        app(OrderMatchingService::class)->runOrderMatching();

        $email->refresh();
        $this->assertSame('P042562296', $email->extracted_po_number);
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
    }

    public function test_carrefour_c4_subject_matches_imported_so(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-C4-1',
            'order_type'          => 'SO',
            'customer_order'      => '26021220',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id' => $this->mailbox->id,
            'message_id'         => 'c4-1',
            'subject'            => 'C4 GCM XGCM     26021220',
            'from_email'         => 'KENCarrefourOrders@maf.ae',
            'received_at'        => now(),
            'folder'             => 'Carrefour POs',
        ]);

        app(OrderMatchingService::class)->runPoExtraction();
        app(OrderMatchingService::class)->runOrderMatching();

        $email->refresh();
        $this->assertSame('26021220', $email->extracted_po_number);
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
    }

    public function test_carrefour_matches_when_acumatica_customer_order_has_manual_formatting(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-C4-FMT',
            'order_type'          => 'SO',
            'customer_order'      => 'C4-KEV-2600050',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id' => $this->mailbox->id,
            'message_id'         => 'c4-fmt',
            'subject'            => 'C4 KEV 2600050',
            'from_email'         => 'KENCarrefourOrders@maf.ae',
            'received_at'        => now(),
            'folder'             => 'Carrefour POs',
        ]);

        app(OrderMatchingService::class)->runPoExtraction();
        app(OrderMatchingService::class)->runOrderMatching();

        $email->refresh();
        $this->assertSame('2600050', $email->canonical_po);
        $this->assertSame('2600050', $email->extracted_po_number);
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
    }

    public function test_naivas_matches_when_acumatica_customer_order_has_manual_formatting(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-FMT',
            'order_type'          => 'SO',
            'customer_order'      => 'PO : 42566300',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id' => $this->mailbox->id,
            'message_id'         => 'naivas-fmt',
            'subject'            => 'Purchase order Confirmation: P042566300 - KIM-FAY EAST AFRICA LIMITED',
            'from_email'         => 'notification@naivas.net',
            'received_at'        => now(),
            'folder'             => 'Naivas POs',
        ]);

        app(OrderMatchingService::class)->runPoExtraction();
        app(OrderMatchingService::class)->runOrderMatching();

        $email->refresh();
        $this->assertSame('42566300', $email->canonical_po);
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
    }

    public function test_naivas_email_ex_vat_total_matches_acumatica_after_vat_uplift(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-AMT',
            'order_type'          => 'SO',
            'customer_name'       => 'Naivas Supermarket',
            'customer_order'      => '42562296',
            'order_total'         => 106348.55,
            'currency_id'         => 'KES',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id' => $this->mailbox->id,
            'message_id'         => 'naivas-amt',
            'subject'            => 'Purchase order Confirmation: P042562296 - KIM-FAY EAST AFRICA LIMITED',
            'from_email'         => 'notification@naivas.net',
            'received_at'        => now(),
            'folder'             => 'Naivas POs',
        ]);

        app(OrderMatchingService::class)->runPoExtraction();
        $email->refresh();
        $email->update(['body_content' => 'Currency: KES Total: 92971.49']);
        app(OrderMatchingService::class)->runOrderMatching();

        $email->refresh();
        $this->assertSame('matched', $email->match_classification);
        $this->assertSame($order->id, $email->matched_order_id);
        $this->assertSame([], $email->match_conflicts);
    }

    public function test_order_match_ai_exact_uses_naivas_translation(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NAIVAS-AI',
            'order_type'          => 'SO',
            'customer_order'      => '42562296',
            'order_date'          => now(),
            'status'              => 'Open',
        ]);

        $email = Email::create([
            'mailbox_account_id'     => $this->mailbox->id,
            'message_id'           => 'naivas-ai',
            'subject'              => 'Purchase order Confirmation: P042562296',
            'from_email'           => 'notification@naivas.net',
            'received_at'          => now(),
            'folder'               => 'Naivas POs',
            'extracted_po_number'  => 'P042562296',
            'canonical_po'         => '42562296',
            'po_extraction_attempted' => true,
            'po_extraction_confidence' => 100,
        ]);

        $prediction = app(OrderMatchAiMatchingService::class)->score($email);

        $this->assertSame(1.0, (float) $prediction->confidence);
        $this->assertSame('exact', $prediction->match_type);
        $this->assertSame($order->id, $prediction->order_id);
    }
}