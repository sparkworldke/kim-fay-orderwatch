<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\ExportDownload;
use App\Models\SignInLog;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserSession;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserActivityExportService
{
    /**
     * Build a 2-sheet workbook: Login + Activity.
     *
     * @param  array{start_date?: string|null, end_date?: string|null, actor_user_id?: int|null}  $filters
     */
    public function streamExcel(array $filters = []): StreamedResponse
    {
        $start = $this->parseDate($filters['start_date'] ?? null, false);
        $end = $this->parseDate($filters['end_date'] ?? null, true);
        $userId = isset($filters['actor_user_id']) ? (int) $filters['actor_user_id'] : null;

        $spreadsheet = new Spreadsheet();

        $loginSheet = $spreadsheet->getActiveSheet();
        $loginSheet->setTitle('Login');
        $this->fillLoginSheet($loginSheet, $start, $end, $userId);

        $activitySheet = $spreadsheet->createSheet();
        $activitySheet->setTitle('Activity');
        $this->fillActivitySheet($activitySheet, $start, $end, $userId);

        $filename = 'user-login-activity-'.now('Africa/Nairobi')->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function fillLoginSheet($sheet, ?Carbon $start, ?Carbon $end, ?int $userId): void
    {
        $headers = [
            'Time (EAT)', 'User ID', 'User Name', 'Email', 'Role',
            'Source', 'Login Mode', 'Status', 'IP Address', 'Device / User Agent',
            'Logout At', 'Duration (seconds)', 'Logout Reason',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);

        $rows = [];

        // Successful/failed attempts from sign_in_logs
        $signIns = SignInLog::query()
            ->with(['user:id,name,email,role'])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end))
            ->orderByDesc('created_at')
            ->limit(20000)
            ->get();

        foreach ($signIns as $log) {
            $user = $log->user;
            $rows[] = [
                $this->fmt($log->created_at),
                $log->user_id,
                $user?->name ?? '',
                $user?->email ?? '',
                $user?->role ?? '',
                'sign_in_log',
                $log->login_mode,
                $log->status,
                $log->ip_address,
                $this->truncate($log->user_agent, 200),
                '',
                '',
                '',
            ];
        }

        // Session open/close (password login often only lands here historically)
        $sessions = UserSession::query()
            ->with(['user:id,name,email,role'])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($start, fn ($q) => $q->where('login_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('login_at', '<=', $end))
            ->orderByDesc('login_at')
            ->limit(20000)
            ->get();

        foreach ($sessions as $session) {
            $user = $session->user;
            $rows[] = [
                $this->fmt($session->login_at),
                $session->user_id,
                $user?->name ?? '',
                $user?->email ?? '',
                $user?->role ?? '',
                'user_session',
                $session->login_mode,
                $session->logout_at ? 'closed' : 'active',
                $session->ip_address,
                $this->truncate($session->user_agent, 200),
                $this->fmt($session->logout_at),
                $session->duration_seconds,
                $session->logout_reason,
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp((string) $b[0], (string) $a[0]));

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function fillActivitySheet($sheet, ?Carbon $start, ?Carbon $end, ?int $userId): void
    {
        $headers = [
            'Time (EAT)', 'User ID', 'User Name', 'Email', 'Role',
            'Activity Type', 'Path / Resource', 'Page Title / Detail', 'IP Address', 'Meta',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        $rows = [];

        // Page navigation & explicit activity posts
        $activities = UserActivityLog::query()
            ->with(['user:id,name,email,role'])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end))
            ->orderByDesc('created_at')
            ->limit(30000)
            ->get();

        foreach ($activities as $row) {
            $user = $row->user;
            $rows[] = [
                $this->fmt($row->created_at),
                $row->user_id,
                $user?->name ?? '',
                $user?->email ?? '',
                $user?->role ?? '',
                $row->activity_type,
                $row->path,
                $row->page_title,
                $row->ip_address,
                $row->meta ? json_encode($row->meta, JSON_UNESCAPED_UNICODE) : '',
            ];
        }

        // Downloads (queued / ready)
        $downloads = ExportDownload::query()
            ->with(['user:id,name,email,role'])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end))
            ->orderByDesc('created_at')
            ->limit(10000)
            ->get();

        foreach ($downloads as $dl) {
            $user = $dl->user;
            $rows[] = [
                $this->fmt($dl->created_at),
                $dl->user_id,
                $user?->name ?? '',
                $user?->email ?? '',
                $user?->role ?? '',
                'download',
                $dl->type,
                trim(($dl->filename ?? '').' · '.$dl->status.($dl->row_count ? " · {$dl->row_count} rows" : '')),
                '',
                json_encode([
                    'status' => $dl->status,
                    'size_bytes' => $dl->size_bytes,
                    'downloaded_at' => optional($dl->downloaded_at)?->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        // Audit log actions (admin config, impersonation, etc.)
        $audits = AuditLog::query()
            ->with(['actor:id,name,email,role'])
            ->when($userId, fn ($q) => $q->where('actor_user_id', $userId))
            ->when($start, fn ($q) => $q->where('timestamp', '>=', $start))
            ->when($end, fn ($q) => $q->where('timestamp', '<=', $end))
            ->orderByDesc('timestamp')
            ->limit(20000)
            ->get();

        foreach ($audits as $audit) {
            $actor = $audit->actor;
            $rows[] = [
                $this->fmt($audit->timestamp),
                $audit->actor_user_id,
                $actor?->name ?? ($audit->actor_user_id ? "User #{$audit->actor_user_id}" : 'system'),
                $actor?->email ?? '',
                $actor?->role ?? '',
                'audit:'.$audit->action_type,
                trim($audit->resource_type.($audit->resource_id ? " #{$audit->resource_id}" : '')),
                $audit->action_type,
                $audit->actor_ip,
                $audit->changes ? json_encode($audit->changes, JSON_UNESCAPED_UNICODE) : '',
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp((string) $b[0], (string) $a[0]));

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function parseDate(mixed $value, bool $endOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $dt = Carbon::parse($value, 'Africa/Nairobi');

            return $endOfDay ? $dt->endOfDay() : $dt->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function fmt(mixed $dt): string
    {
        if (! $dt) {
            return '';
        }
        try {
            return Carbon::parse($dt)->timezone('Africa/Nairobi')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $dt;
        }
    }

    private function truncate(?string $value, int $max): string
    {
        $value = (string) ($value ?? '');
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1).'…';
    }
}
