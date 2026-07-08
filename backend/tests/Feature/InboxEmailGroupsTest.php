<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxEmailGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_groups_endpoint_groups_emails_by_mapped_customer_with_stats(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'Customer Service Agent']);
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);
        $customer = AcumaticaCustomer::create([
            'acumatica_id' => 'NAIVAS-663',
            'name' => 'Naivas',
            'is_main_account' => true,
        ]);
        $folder = MailboxFolder::create([
            'mailbox_account_id' => $account->id,
            'external_folder_id' => 'naivas-id',
            'display_name' => 'Naivas POs',
            'customer_id' => $customer->id,
            'is_sync_enabled' => true,
            'trust_level' => 'trusted_order',
        ]);

        Email::create([
            'mailbox_account_id' => $account->id,
            'mailbox_folder_id' => $folder->id,
            'message_id' => 'msg-po-1',
            'subject' => 'PO confirmation',
            'from_email' => 'notification@naivas.net',
            'folder' => 'Naivas POs',
            'received_at' => '2026-06-24 10:00:00',
            'ingestion_classification' => 'po_processing',
            'extracted_po_number' => 'PO123',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'mailbox_folder_id' => $folder->id,
            'message_id' => 'msg-plain-1',
            'subject' => 'Delivery schedule',
            'from_email' => 'donotreply@naivas.net',
            'folder' => 'Naivas POs',
            'received_at' => '2026-06-24 11:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'message_id' => 'msg-unassigned',
            'subject' => 'General inbox',
            'from_email' => 'other@example.com',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 12:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/emails/inbox-groups?group_by=customer&date_from=2026-06-24&date_to=2026-06-24')
            ->assertOk();

        $response->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.with_po', 1)
            ->assertJsonPath('stats.po_processing', 1)
            ->assertJsonPath('stats.stored_non_order', 2);

        $groups = collect($response->json('groups'));
        $naivas = $groups->firstWhere('customer_name', 'Naivas');
        $this->assertNotNull($naivas);
        $this->assertSame('NAIVAS-663', $naivas['acumatica_id']);
        $this->assertSame(2, $naivas['email_count']);
        $this->assertSame(1, $naivas['with_po_count']);

        $unassigned = $groups->firstWhere('customer_name', 'Unassigned');
        $this->assertNotNull($unassigned);
        $this->assertSame(1, $unassigned['email_count']);
    }

    public function test_inbox_groups_default_to_customer_domain_grouping(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'Customer Service Agent']);
        $account = MailboxAccount::create([
            'email' => 'inbox@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);
        $customer = AcumaticaCustomer::create([
            'acumatica_id' => 'QUICKMART',
            'name' => 'Quickmart',
            'is_main_account' => true,
        ]);
        $config = EmailImportConfig::create([
            'sender_pattern' => '*@quickmart.co.ke',
            'match_mode' => EmailImportConfig::MATCH_MODE_WILDCARD,
            'is_wildcard' => true,
            'display_name' => 'Quickmart POs',
            'customer_id' => $customer->id,
            'is_active' => true,
            'approval_status' => EmailImportConfig::APPROVAL_APPROVED,
        ]);

        Email::create([
            'mailbox_account_id' => $account->id,
            'email_import_config_id' => $config->id,
            'message_id' => 'quickmart-1',
            'subject' => 'Quickmart PO',
            'from_email' => 'orders@quickmart.co.ke',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 10:00:00',
            'ingestion_classification' => 'po_processing',
            'canonical_po' => 'QM123',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'message_id' => 'gmail-1',
            'subject' => 'Generic request',
            'from_email' => 'buyer@gmail.com',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 11:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'message_id' => 'gmail-2',
            'subject' => 'Another generic request',
            'from_email' => 'other@GMAIL.COM',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 11:30:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'message_id' => 'outlook-1',
            'subject' => 'Outlook request',
            'from_email' => 'person@outlook.com',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 12:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'message_id' => 'unknown-domain-1',
            'subject' => 'Unknown business',
            'from_email' => 'buyer@new-retailer.co.ke',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 13:00:00',
            'ingestion_classification' => 'needs_review',
            'is_read' => false,
        ]);
        Email::create([
            'mailbox_account_id' => $account->id,
            'message_id' => 'invalid-sender-1',
            'subject' => 'Invalid sender',
            'from_email' => 'not-an-email',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 14:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/emails/inbox-groups?date_from=2026-06-24&date_to=2026-06-24')
            ->assertOk()
            ->assertJsonPath('group_by', 'domain')
            ->assertJsonPath('stats.total', 6)
            ->assertJsonPath('stats.with_po', 1)
            ->assertJsonPath('stats.needs_review', 1);

        $groups = collect($response->json('groups'));

        $quickmart = $groups->firstWhere('group_label', 'Quickmart');
        $this->assertNotNull($quickmart);
        $this->assertSame('quickmart.co.ke', $quickmart['domain']);
        $this->assertSame('QUICKMART', $quickmart['acumatica_id']);
        $this->assertSame(1, $quickmart['email_count']);
        $this->assertSame(1, $quickmart['with_po_count']);

        $gmail = $groups->firstWhere('group_label', 'gmail.com');
        $this->assertNotNull($gmail);
        $this->assertSame(2, $gmail['email_count']);
        $this->assertNull($gmail['customer_id']);

        $outlook = $groups->firstWhere('group_label', 'outlook.com');
        $this->assertNotNull($outlook);
        $this->assertSame(1, $outlook['email_count']);

        $unknownBusiness = $groups->firstWhere('group_label', 'new-retailer.co.ke');
        $this->assertNotNull($unknownBusiness);
        $this->assertSame(1, $unknownBusiness['needs_review_count']);
        $this->assertSame(1, $unknownBusiness['unread_count']);

        $unknownDomain = $groups->firstWhere('group_label', 'Unknown domain');
        $this->assertNotNull($unknownDomain);
        $this->assertSame(1, $unknownDomain['email_count']);
    }

    public function test_customer_domain_grouping_respects_search_date_and_mailbox_filters(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => 'Customer Service Agent']);
        $includedAccount = MailboxAccount::create([
            'email' => 'included@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);
        $excludedAccount = MailboxAccount::create([
            'email' => 'excluded@example.com',
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'status' => 'connected',
        ]);

        Email::create([
            'mailbox_account_id' => $includedAccount->id,
            'message_id' => 'included-1',
            'subject' => 'Target subject',
            'from_email' => 'orders@filtermatch.co.ke',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 10:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $includedAccount->id,
            'message_id' => 'wrong-date',
            'subject' => 'Target subject',
            'from_email' => 'orders@filtermatch.co.ke',
            'folder' => 'Inbox',
            'received_at' => '2026-06-23 10:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $excludedAccount->id,
            'message_id' => 'wrong-mailbox',
            'subject' => 'Target subject',
            'from_email' => 'orders@filtermatch.co.ke',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 10:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);
        Email::create([
            'mailbox_account_id' => $includedAccount->id,
            'message_id' => 'wrong-search',
            'subject' => 'Other subject',
            'from_email' => 'orders@filtermatch.co.ke',
            'folder' => 'Inbox',
            'received_at' => '2026-06-24 10:00:00',
            'ingestion_classification' => 'stored_non_order',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/emails/inbox-groups?mailbox_id='.$includedAccount->id.'&search=Target&date_from=2026-06-24&date_to=2026-06-24')
            ->assertOk()
            ->assertJsonPath('stats.total', 1);

        $this->assertSame('filtermatch.co.ke', $response->json('groups.0.group_label'));
        $this->assertSame(1, $response->json('groups.0.email_count'));
    }
}
