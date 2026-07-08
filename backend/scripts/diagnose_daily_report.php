<?php

/**
 * Run on production to find why scheduled daily emails did not send:
 *   php scripts/diagnose_daily_report.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DailyReportConfig;
use App\Models\DailyReportRun;
use App\Support\MailTransportValidator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

$config = DailyReportConfig::singleton();
$tz = $config->timezone ?: 'Africa/Nairobi';
$now = now()->timezone($tz);

echo "=== Daily Report Diagnostics ===\n";
echo 'Server time: '.$now->format('Y-m-d H:i:s T')."\n";
echo 'Report date (yesterday): '.$now->copy()->subDay()->toDateString()."\n\n";

echo "--- Config ---\n";
echo 'is_enabled: '.($config->is_enabled ? 'yes' : 'NO (emails skipped)')."\n";
echo 'send_time (admin UI): '.($config->send_time ?? 'n/a')." (note: fixed scheduler uses 07:00 Tue–Sat, not this field)\n";
echo 'timezone: '.$tz."\n";
echo 'reply_to: '.json_encode($config->replyTo())."\n";
echo 'recipients (cc): '.json_encode($config->recipients())."\n";
if ($config->replyTo() === [] && $config->recipients() === []) {
    echo "!! No recipients configured — email will be skipped.\n";
}

echo "\n--- Mail ---\n";
echo 'APP_ENV: '.config('app.env')."\n";
echo 'MAIL_MAILER: '.config('mail.default')."\n";
echo 'MAIL_FROM: '.config('mail.from.address')."\n";
if (config('mail.default') === 'smtp') {
    echo 'SMTP host: '.(config('mail.mailers.smtp.host') ?: '(missing)')."\n";
}
if (config('mail.default') === 'resend') {
    echo 'RESEND_API_KEY: '.(config('services.resend.key') ? 'set' : 'MISSING')."\n";
}
try {
    MailTransportValidator::assertConfigured();
    echo "Mail transport: OK\n";
} catch (Throwable $e) {
    echo 'Mail transport: FAILED — '.$e->getMessage()."\n";
}

echo "\n--- Scheduler ---\n";
echo 'CRON_TIMEZONE: '.config('cron.timezone', config('app.timezone'))."\n";
$schedule = app(Schedule::class);
$event = collect($schedule->events())->first(
    fn ($e) => is_string($e->command ?? null) && str_contains($e->command, 'send-daily-report-fixed'),
);
if ($event) {
    echo 'Cron expression: '.$event->expression."\n";
    echo 'Next due: '.$event->nextRunDate()->timezone($tz)->format('Y-m-d H:i:s T')."\n";
    echo 'Is due right now: '.($event->isDue(app()) ? 'yes' : 'no')."\n";
    echo "Schedule: Tue–Sat 07:00 only (no send Sun/Mon mornings).\n";
} else {
    echo "!! send-daily-report-fixed not found in schedule — deploy latest code.\n";
}

echo "\n--- Recent runs (daily_report_runs) ---\n";
$runs = DailyReportRun::query()->orderByDesc('id')->limit(5)->get();
if ($runs->isEmpty()) {
    echo "No runs recorded — scheduler may never have triggered the command.\n";
} else {
    foreach ($runs as $run) {
        echo sprintf(
            "#%d %s status=%s delivery=%s recipients=%s error=%s\n",
            $run->id,
            $run->report_date?->format('Y-m-d') ?? $run->report_date,
            $run->status,
            $run->delivery_status ?? 'n/a',
            $run->recipient_count ?? 0,
            $run->error_summary ? str_replace("\n", ' | ', $run->error_summary) : '-',
        );
    }
}

echo "\n--- Fix / test ---\n";
echo "Manual send: php artisan orderwatch:send-daily-report-fixed --source=manual --force\n";
echo "View logs:     tail -100 storage/logs/laravel.log | grep daily_report\n";