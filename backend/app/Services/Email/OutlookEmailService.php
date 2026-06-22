<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Models\Email;
use App\Models\MailboxAccount;
use App\Services\Admin\EncryptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OutlookEmailService implements EmailProviderInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $tenantId;
    private string $redirectUri;

    public function __construct(private readonly EncryptionService $encryption)
    {
        $this->clientId     = config('services.microsoft.client_id', '');
        $this->clientSecret = config('services.microsoft.client_secret', '');
        $this->tenantId     = config('services.microsoft.tenant_id', 'common');
        $this->redirectUri  = config('services.microsoft.redirect_uri', '');
    }

    public function getAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'Mail.Read offline_access',
            'response_mode' => 'query',
            'state'         => $state,
        ]);

        return "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?{$params}";
    }

    public function handleCallback(string $code): MailboxAccount
    {
        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('OAuth code exchange failed: ' . $response->body());
        }

        $tokenData = $response->json();

        $profile = Http::withToken($tokenData['access_token'])
            ->get('https://graph.microsoft.com/v1.0/me')
            ->json();

        $email = $profile['mail'] ?? $profile['userPrincipalName'] ?? '';

        return MailboxAccount::updateOrCreate(
            ['email' => $email],
            [
                'display_name'              => $profile['displayName'] ?? $email,
                'access_token_encrypted'    => $this->encryption->encrypt($tokenData['access_token']),
                'refresh_token_encrypted'   => $this->encryption->encrypt($tokenData['refresh_token'] ?? ''),
                'token_expires_at'          => now()->addSeconds((int) ($tokenData['expires_in'] ?? 3600)),
                'status'                    => 'connected',
                'delta_token'               => null,
            ]
        );
    }

    public function syncEmails(MailboxAccount $account): int
    {
        $accessToken = $this->getValidAccessToken($account);

        $url    = 'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta';
        $params = ['$select' => 'id,subject,from,toRecipients,bodyPreview,isRead,receivedDateTime'];

        if ($account->delta_token) {
            $url    = $account->delta_token;
            $params = [];
        }

        $count = 0;

        do {
            $response = Http::withToken($accessToken)->get($url, $params);

            if (! $response->successful()) {
                throw new RuntimeException('Microsoft Graph error: ' . $response->body());
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $message) {
                if (isset($message['@removed'])) {
                    Email::where('message_id', $message['id'])->delete();
                    continue;
                }

                Email::updateOrCreate(
                    ['message_id' => $message['id']],
                    [
                        'mailbox_account_id' => $account->id,
                        'subject'            => $message['subject'] ?? '(no subject)',
                        'from_email'         => $message['from']['emailAddress']['address'] ?? null,
                        'from_name'          => $message['from']['emailAddress']['name'] ?? null,
                        'to_recipients'      => array_map(
                            fn ($r) => $r['emailAddress'],
                            $message['toRecipients'] ?? []
                        ),
                        'body_preview'       => substr($message['bodyPreview'] ?? '', 0, 500),
                        'is_read'            => $message['isRead'] ?? false,
                        'received_at'        => isset($message['receivedDateTime'])
                            ? new Carbon($message['receivedDateTime'])
                            : null,
                        'folder'             => 'Inbox',
                    ]
                );

                $count++;
            }

            if (isset($data['@odata.deltaLink'])) {
                $account->update([
                    'delta_token'    => $data['@odata.deltaLink'],
                    'last_synced_at' => now(),
                    'status'         => 'connected',
                ]);
            }

            $url    = $data['@odata.nextLink'] ?? null;
            $params = [];

        } while ($url);

        return $count;
    }

    public function refreshAccessToken(MailboxAccount $account): void
    {
        $refreshToken = $this->encryption->decrypt($account->refresh_token_encrypted);

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]
        );

        if (! $response->successful()) {
            $account->update(['status' => 'error']);
            throw new RuntimeException('Token refresh failed: ' . $response->body());
        }

        $tokenData = $response->json();

        $account->update([
            'access_token_encrypted'  => $this->encryption->encrypt($tokenData['access_token']),
            'refresh_token_encrypted' => isset($tokenData['refresh_token'])
                ? $this->encryption->encrypt($tokenData['refresh_token'])
                : $account->refresh_token_encrypted,
            'token_expires_at' => now()->addSeconds((int) ($tokenData['expires_in'] ?? 3600)),
            'status'           => 'connected',
        ]);
    }

    /** Public accessor so controllers can get a (possibly refreshed) token for diagnostics. */
    public function getDecryptedToken(MailboxAccount $account): string
    {
        return $this->getValidAccessToken($account);
    }

    private function getValidAccessToken(MailboxAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isBefore(now()->addMinutes(5))) {
            $this->refreshAccessToken($account);
            $account->refresh();
        }

        return $this->encryption->decrypt($account->access_token_encrypted) ?? '';
    }
}
