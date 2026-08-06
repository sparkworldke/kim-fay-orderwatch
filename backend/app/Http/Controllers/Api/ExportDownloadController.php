<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExportDownload;
use App\Services\Exports\ExportDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDownloadController extends Controller
{
    public function __construct(
        private readonly ExportDownloadService $exports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $rows = ExportDownload::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ExportDownload $d) => $d->toApiArray())
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', ExportDownload::TYPES)],
            'filters' => ['nullable', 'array'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:255'],
            'delivery_mode' => ['nullable', 'string', 'in:dashboard,attachment,link'],
        ]);

        $download = $this->exports->queue(
            $user,
            $validated['type'],
            is_array($validated['filters'] ?? null) ? $validated['filters'] : [],
            $validated['recipient_email'] ?? null,
            $validated['delivery_mode'] ?? 'dashboard',
        );

        return response()->json([
            'message' => 'Export queued. Open Downloads when it is ready.',
            'download' => $download->toApiArray(),
        ], 202);
    }

    public function publicFile(string $token): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $download = ExportDownload::query()->where('public_token', $token)->firstOrFail();
        $download->markExpiredIfNeeded();
        $download->refresh();

        if (! $download->isReady()) {
            return response()->json(['message' => 'This download is unavailable or has expired.'], 410);
        }

        $download->update(['downloaded_at' => now()]);

        return Storage::disk($download->disk)->download(
            $download->path,
            $download->filename ?? basename((string) $download->path),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function show(Request $request, ExportDownload $download): JsonResponse
    {
        $this->authorizeOwner($request, $download);

        return response()->json(['download' => $download->fresh()?->toApiArray() ?? $download->toApiArray()]);
    }

    public function file(Request $request, ExportDownload $download): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->authorizeOwner($request, $download);
        $download->markExpiredIfNeeded();
        $download->refresh();

        if (! $download->isReady()) {
            return response()->json([
                'message' => $download->status === ExportDownload::STATUS_FAILED
                    ? ('Export failed: '.($download->error_message ?? 'unknown error'))
                    : 'Export is not ready yet.',
                'status' => $download->status,
            ], 409);
        }

        $download->update(['downloaded_at' => now()]);

        // Laravel's Storage::download() may return BinaryFileResponse or StreamedResponse
        // depending on the driver / version.
        return Storage::disk($download->disk)->download(
            $download->path,
            $download->filename ?? basename((string) $download->path),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function destroy(Request $request, ExportDownload $download): JsonResponse
    {
        $this->authorizeOwner($request, $download);

        if ($download->path) {
            Storage::disk($download->disk)->delete($download->path);
        }
        $download->delete();

        return response()->json(['message' => 'Download removed.']);
    }

    private function authorizeOwner(Request $request, ExportDownload $download): void
    {
        $user = $request->user();
        abort_unless($user && (int) $user->id === (int) $download->user_id, 403);
    }
}
