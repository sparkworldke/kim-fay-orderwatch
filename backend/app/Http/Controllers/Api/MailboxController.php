<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMailboxJob;
use App\Models\MailboxAccount;
use App\Services\Email\OutlookEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class MailboxController extends Controller
{
    public function __construct(private readonly OutlookEmailService $outlook) {}

    public function index(): JsonResponse
    {
        $accounts = MailboxAccount::orderByDesc('last_synced_at')->get();

        return response()->json($accounts->map(fn ($a) => $this->present($a)));
    }

    public function startOAuth(Request $request): JsonResponse
    {
        $state = Str::random(32);
        Cache::put('mailbox_oauth_state_' . $state, true, now()->addMinutes(15));

        return response()->json([
            'auth_url' => $this->outlook->getAuthUrl($state),
        ]);
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        $frontendBase = rtrim(config('services.microsoft.frontend_url', 'http://localhost:5173'), '/');

        $error = $request->query('error');
        if ($error) {
            return redirect("{$frontendBase}/app/mailbox?error=" . urlencode($request->query('error_description', $error)));
        }

        $state = $request->query('state', '');
        if (empty($state) || ! Cache::pull('mailbox_oauth_state_' . $state)) {
            return redirect("{$frontendBase}/app/mailbox?error=invalid_state");
        }

        $code = $request->query('code', '');
        if (empty($code)) {
            return redirect("{$frontendBase}/app/mailbox?error=missing_code");
        }

        try {
            $account = $this->outlook->handleCallback($code);
            SyncMailboxJob::dispatchSync($account->id);

            return redirect("{$frontendBase}/app/mailbox?connected=1&email=" . urlencode($account->email));
        } catch (Throwable $exception) {
            return redirect("{$frontendBase}/app/mailbox?error=" . urlencode($exception->getMessage()));
        }
    }

    public function update(Request $request, MailboxAccount $mailbox): JsonResponse
    {
        $validated = $request->validate([
            'sync_from_date' => 'nullable|date_format:Y-m-d',
        ]);

        // Changing the from-date invalidates the existing delta position so the
        // next sync re-fetches from the new date rather than where it left off.
        $newDate = $validated['sync_from_date'] ?? null;
        $oldDate = $mailbox->sync_from_date?->format('Y-m-d');

        if ($newDate !== $oldDate) {
            $validated['delta_token']    = null;
            $validated['last_synced_at'] = null;
        }

        $mailbox->update($validated);

        return response()->json($this->present($mailbox));
    }

    public function sync(MailboxAccount $mailbox): JsonResponse
    {
        SyncMailboxJob::dispatchSync($mailbox->id);

        return response()->json(['message' => 'Sync completed.']);
    }

    public function destroy(MailboxAccount $mailbox): JsonResponse
    {
        $mailbox->delete();

        return response()->json(['message' => 'Mailbox disconnected.']);
    }

    public function checkOAuth(): JsonResponse
    {
        $clientId     = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');
        $tenantId     = config('services.microsoft.tenant_id', 'common');
        $redirectUri  = config('services.microsoft.redirect_uri');

        $checks = [];

        // 1. Credentials configured
        $checks['credentials_configured'] = [
            'ok'      => ! empty($clientId) && ! empty($clientSecret) && ! empty($tenantId),
            'label'   => 'Credentials configured',
            'detail'  => ! empty($clientId)
                ? 'Client ID, Secret and Tenant ID are set in .env'
                : 'Missing MICROSOFT_CLIENT_ID, CLIENT_SECRET or TENANT_ID in .env',
        ];

        // 2. Redirect URI set
        $checks['redirect_uri'] = [
            'ok'     => ! empty($redirectUri),
            'label'  => 'Redirect URI',
            'detail' => $redirectUri ?: 'MICROSOFT_REDIRECT_URI is not set',
        ];

        // 3. Tenant reachable — hit the OpenID discovery endpoint (no credentials needed)
        $tenantOk    = false;
        $tenantDetail = '';
        try {
            $resp = Http::timeout(8)->get(
                "https://login.microsoftonline.com/{$tenantId}/v2.0/.well-known/openid-configuration"
            );
            $tenantOk     = $resp->successful();
            $tenantDetail = $tenantOk
                ? "Tenant {$tenantId} is reachable via Microsoft login"
                : "Tenant returned HTTP {$resp->status()} — check MICROSOFT_TENANT_ID";
        } catch (Throwable $e) {
            $tenantDetail = 'Cannot reach login.microsoftonline.com: ' . $e->getMessage();
        }
        $checks['tenant_reachable'] = ['ok' => $tenantOk, 'label' => 'Tenant reachable', 'detail' => $tenantDetail];

        // 4. App registration valid — attempt token exchange with an intentionally bad code
        //    A valid app returns "invalid_grant"; an invalid app returns "invalid_client" / "unauthorized_client"
        $appOk     = false;
        $appDetail = '';
        if ($checks['credentials_configured']['ok'] && $tenantOk) {
            try {
                $resp = Http::asForm()->timeout(8)->post(
                    "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                    [
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'code'          => 'test_probe_code',
                        'redirect_uri'  => $redirectUri,
                        'grant_type'    => 'authorization_code',
                    ]
                );
                $body  = $resp->json();
                $error = $body['error'] ?? '';

                if ($error === 'invalid_grant') {
                    $appOk     = true;
                    $appDetail = 'App registration valid — client credentials accepted by Microsoft';
                } elseif (in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_request'])) {
                    $appOk     = false;
                    $appDetail = 'App registration rejected: ' . ($body['error_description'] ?? $error);
                } else {
                    $appOk     = false;
                    $appDetail = 'Unexpected response: ' . ($body['error_description'] ?? json_encode($body));
                }
            } catch (Throwable $e) {
                $appDetail = 'Could not reach Microsoft token endpoint: ' . $e->getMessage();
            }
        } else {
            $appDetail = 'Skipped — credentials or tenant not configured';
        }
        $checks['app_registration'] = ['ok' => $appOk, 'label' => 'App registration', 'detail' => $appDetail];

        // 5. Connected mailboxes — verify live token for each
        $mailboxStatuses = MailboxAccount::all()->map(function (MailboxAccount $account) {
            try {
                $resp = Http::withToken(
                    $this->outlook->getDecryptedToken($account)
                )->timeout(8)->get('https://graph.microsoft.com/v1.0/me');

                if ($resp->successful()) {
                    return [
                        'email'  => $account->email,
                        'ok'     => true,
                        'detail' => 'Token valid — Graph API reachable',
                    ];
                }
                return [
                    'email'  => $account->email,
                    'ok'     => false,
                    'detail' => "Graph returned HTTP {$resp->status()}: " . ($resp->json('error.message') ?? $resp->body()),
                ];
            } catch (Throwable $e) {
                return ['email' => $account->email, 'ok' => false, 'detail' => $e->getMessage()];
            }
        })->values()->all();

        $allOk = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json([
            'overall_ok'       => $allOk,
            'checks'           => $checks,
            'mailbox_tokens'   => $mailboxStatuses,
            'checked_at'       => now()->toISOString(),
        ]);
    }

    public function syncLogs(MailboxAccount $mailbox): JsonResponse
    {
        return response()->json(
            $mailbox->syncLogs()->orderByDesc('started_at')->limit(20)->get()
        );
    }

    private function present(MailboxAccount $account): array
    {
        return [
            'id'             => $account->id,
            'email'          => $account->email,
            'display_name'   => $account->display_name,
            'status'         => $account->status,
            'last_synced_at' => $account->last_synced_at,
            'sync_from_date' => $account->sync_from_date?->format('Y-m-d'),
            'created_at'     => $account->created_at,
        ];
    }
}
