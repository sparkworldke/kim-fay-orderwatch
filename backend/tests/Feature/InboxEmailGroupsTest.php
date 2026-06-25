<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\Email;
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
        $user = User::factory()->create(['is_active' => true]);
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
            ->getJson('/api/emails/inbox-groups?date_from=2026-06-24&date_to=2026-06-24')
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
}