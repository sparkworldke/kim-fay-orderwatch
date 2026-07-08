<?php

namespace Tests\Feature;

use App\Mail\SyncMonitorAlertMail;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\Email;
use App\Models\MailboxAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SyncMonitorAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_monitor_sends_alert_on_successful_sync_and_guardrail_failure(): void
    {
        Mail::fake();

        $job = CronJob::emailSync();
        CronJob::syncMonitor();

        $run = CronRunLog::create([
            'cron_job_id' => $job->id,
            'scheduled_at' => now(),
            'started_at' => now(),
            'ended_at' => now(),
            'status' => 'success',
            'duration_ms' => 100,
            'emails_processed' => 3,
            'step_status' => [],
        ]);

        MailboxAccount::create([
            'email' => 'inbox@example.com',
            'display_name' => 'Inbox',
            'access_token_encrypted' => 'token',
            'refresh_token_encrypted' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        Email::create([
            'mailbox_account_id' => MailboxAccount::first()->id,
            'message_id' => 'm1',
            'subject' => 'Subject',
            'from_email' => 'unknown@example.com',
            'to_recipients' => [],
            'body_preview' => 'Preview',
            'is_read' => false,
            'received_at' => now(),
            'folder' => 'Inbox',
            'has_attachments' => false,
            'import_guardrail_status' => 'unrecognized',
            'import_guardrail_reason' => 'sender_not_preapproved',
        ]);

        $this->artisan('orderwatch:sync-monitor --source=scheduler')
            ->assertExitCode(0);

        Mail::assertSent(SyncMonitorAlertMail::class, function (SyncMonitorAlertMail $mail): bool {
            return $mail->hasTo('commercialtechlead@kimfay.com');
        });

        Mail::fake();
        $this->artisan('orderwatch:sync-monitor --source=scheduler')
            ->assertExitCode(0);
        Mail::assertNothingSent();
    }
}

