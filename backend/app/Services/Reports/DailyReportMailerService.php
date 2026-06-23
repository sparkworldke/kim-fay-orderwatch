<?php

namespace App\Services\Reports;

use App\Mail\DailyManagementReportMail;
use App\Models\DailyReportConfig;
use App\Models\DailyReportDeliveryLog;
use App\Models\DailyReportRun;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DailyReportMailerService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $insights
     * @return array{delivery_status: string, sent_count: int, failed_count: int, errors: list<string>}
     */
    public function send(DailyReportRun $run, DailyReportConfig $config, array $payload, array $insights): array
    {
        $routing = $this->resolveRouting($config);
        if ($routing['to'] === [] && $routing['cc'] === []) {
            return [
                'delivery_status' => 'skipped',
                'sent_count' => 0,
                'failed_count' => 0,
                'errors' => ['No Reply-To or CC recipients configured.'],
            ];
        }

        $subject = $this->buildSubject($config, $payload);
        $logs = [];

        foreach ($routing['to'] as $email) {
            $logs[] = $this->pendingLog($run, $email, 'to');
        }
        foreach ($routing['cc'] as $email) {
            $logs[] = $this->pendingLog($run, $email, 'cc');
        }

        try {
            $pending = Mail::to($routing['to']);
            if ($routing['cc'] !== []) {
                $pending->cc($routing['cc']);
            }
            $pending->send(new DailyManagementReportMail($subject, $payload, $insights, $config));

            foreach ($logs as $log) {
                $log->update(['delivery_status' => 'sent']);
            }

            return [
                'delivery_status' => 'sent',
                'sent_count' => count($logs),
                'failed_count' => 0,
                'errors' => [],
            ];
        } catch (Throwable $e) {
            foreach ($logs as $log) {
                $log->update([
                    'delivery_status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            return [
                'delivery_status' => 'failed',
                'sent_count' => 0,
                'failed_count' => count($logs),
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /** @return array{to: list<string>, cc: list<string>} */
    private function resolveRouting(DailyReportConfig $config): array
    {
        $to = $config->replyTo();
        $cc = array_values(array_diff($config->recipients(), $to));

        if ($to === [] && $cc !== []) {
            $to = [array_shift($cc)];
        }

        return ['to' => $to, 'cc' => $cc];
    }

    private function pendingLog(DailyReportRun $run, string $email, string $role): DailyReportDeliveryLog
    {
        return DailyReportDeliveryLog::create([
            'daily_report_run_id' => $run->id,
            'recipient_email' => $email,
            'recipient_role' => $role,
            'delivery_status' => 'pending',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function buildSubject(DailyReportConfig $config, array $payload): string
    {
        $template = $config->subject_template ?: 'OrderWatch Daily Brief – {report_date}';

        return str_replace(
            ['{report_date}', '{date}'],
            [$payload['report_date_label'] ?? '', $payload['report_date'] ?? ''],
            $template,
        );
    }
}