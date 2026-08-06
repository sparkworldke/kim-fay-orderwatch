<?php

namespace App\Services\Exports;

use App\Http\Controllers\Api\OperationsController;
use App\Jobs\ProcessExportDownloadJob;
use App\Models\ExportDownload;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExportDownloadService
{
    /**
     * Queue a background export for the user.
     *
     * @param  array<string, mixed>  $filters
     */
    public function queue(
        User $user,
        string $type,
        array $filters = [],
        ?string $recipientEmail = null,
        string $deliveryMode = 'dashboard',
    ): ExportDownload
    {
        if (! in_array($type, ExportDownload::TYPES, true)) {
            abort(422, 'Unsupported export type.');
        }

        $download = ExportDownload::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'recipient_email' => $recipientEmail ? trim($recipientEmail) : null,
            'delivery_mode' => $recipientEmail ? $deliveryMode : 'dashboard',
            'public_token' => $recipientEmail ? Str::random(64) : null,
            'status' => ExportDownload::STATUS_QUEUED,
            'filters' => $this->sanitizeFilters($filters),
            'expires_at' => now()->addDays(3),
        ]);

        // Return 202 immediately; build Excel after the HTTP response (sync driver)
        // or on a queue worker (redis/database/sqs).
        ProcessExportDownloadJob::dispatch($download->id)->afterResponse();

        return $download->fresh() ?? $download;
    }

    public function process(ExportDownload $download): void
    {
        $download->update([
            'status' => ExportDownload::STATUS_PROCESSING,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $user = User::query()->find($download->user_id);
            if (! $user) {
                throw new \RuntimeException('User no longer exists.');
            }

            $request = $this->requestFor($download, $user);
            $response = $this->runExport($download->type, $request);

            if ($response instanceof JsonResponse) {
                $payload = $response->getData(true);
                $message = is_array($payload)
                    ? (string) ($payload['message'] ?? 'Export failed.')
                    : 'Export failed.';
                throw new \RuntimeException($message);
            }

            if (! $response instanceof StreamedResponse) {
                throw new \RuntimeException('Unexpected export response type.');
            }

            $filename = $this->filenameFromResponse($response)
                ?? $this->defaultFilename($download->type);
            $relativePath = 'export-downloads/'.$download->user_id.'/'.$download->id.'_'.$filename;

            $disk = Storage::disk('local');
            $disk->makeDirectory(dirname($relativePath));
            $absolute = $disk->path($relativePath);

            $content = $this->captureStream($response);
            file_put_contents($absolute, $content);

            $download->update([
                'status' => ExportDownload::STATUS_READY,
                'filename' => $filename,
                'disk' => 'local',
                'path' => $relativePath,
                'size_bytes' => strlen($content),
                'finished_at' => now(),
                'expires_at' => now()->addDays(3),
                'error_message' => null,
            ]);

            $this->emailReadyNotification($user, $download->fresh() ?? $download);
        } catch (Throwable $e) {
            $download->update([
                'status' => ExportDownload::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
    }

    private function emailReadyNotification(User $user, ExportDownload $download): void
    {
        $recipient = $download->recipient_email ?: $user->email;
        if (! is_string($recipient) || trim($recipient) === '') {
            return;
        }

        try {
            $downloadsUrl = $download->recipient_email && $download->public_token
                ? url('/api/public/downloads/'.$download->public_token)
                : FrontendUrl::path('/app/downloads');
            $filename = e($download->filename ?? 'Your export');
            $name = e($user->name ?: 'there');
            $url = e($downloadsUrl);

            $attach = $download->recipient_email
                && $download->delivery_mode === 'attachment'
                && ($download->size_bytes ?? PHP_INT_MAX) <= 10 * 1024 * 1024;
            Mail::html(
                "<p>Hello {$name},</p>"
                .'<p><strong>Your download is ready.</strong></p>'
                ."<p>{$filename} has finished processing and will remain available for approximately three days.</p>"
                ."<p><a href=\"{$url}\">Follow this link to download your file</a></p>",
                function ($message) use ($recipient, $download, $attach): void {
                    $message->to($recipient)->subject('Your Kim-Fay Sight download is ready');
                    if ($attach) {
                        $message->attach(
                            Storage::disk($download->disk)->path($download->path),
                            ['as' => $download->filename, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                        );
                    }
                },
            );
            $download->update(['emailed_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Export completed but ready notification email failed.', [
                'download_id' => $download->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = array_values(array_filter($value, static fn ($v) => $v !== null && $v !== ''));
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function requestFor(ExportDownload $download, User $user): Request
    {
        $path = match ($download->type) {
            ExportDownload::TYPE_BACKORDERS => '/api/operations/backorders/export',
            ExportDownload::TYPE_FILL_RATE => '/api/operations/fill-rate/export',
            ExportDownload::TYPE_INVENTORY => '/api/operations/inventory/export',
            ExportDownload::TYPE_ITEMS_NOT_DELIVERED => '/api/operations/items-not-delivered/export',
            default => '/api/operations/backorders/export',
        };

        $request = Request::create($path, 'GET', $download->filters ?? []);
        $request->setUserResolver(static fn () => $user);
        $request->headers->set('Accept', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        return $request;
    }

    private function runExport(string $type, Request $request): JsonResponse|StreamedResponse
    {
        /** @var OperationsController $controller */
        return match ($type) {
            ExportDownload::TYPE_BACKORDERS => app(OperationsController::class)->exportBackorders($request),
            ExportDownload::TYPE_FILL_RATE => app(OperationsController::class)->exportFillRate($request),
            ExportDownload::TYPE_INVENTORY => app(OperationsController::class)->exportInventory($request),
            ExportDownload::TYPE_ITEMS_NOT_DELIVERED => app(\App\Http\Controllers\Api\ItemsNotDeliveredController::class)->export($request),
            default => throw new \InvalidArgumentException("Unknown export type: {$type}"),
        };
    }

    private function captureStream(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function filenameFromResponse(StreamedResponse $response): ?string
    {
        $disposition = $response->headers->get('Content-Disposition');
        if (! is_string($disposition) || $disposition === '') {
            return null;
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return basename($m[1]);
        }

        return null;
    }

    private function defaultFilename(string $type): string
    {
        $stamp = now()->format('Ymd-Hi');

        return match ($type) {
            ExportDownload::TYPE_BACKORDERS => "backorders-export-{$stamp}.xlsx",
            ExportDownload::TYPE_FILL_RATE => "fill-rate-export-{$stamp}.xlsx",
            ExportDownload::TYPE_INVENTORY => "inventory-export-{$stamp}.xlsx",
            ExportDownload::TYPE_ITEMS_NOT_DELIVERED => "items-not-delivered-{$stamp}.xlsx",
            default => "export-{$stamp}.xlsx",
        };
    }
}
