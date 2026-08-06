<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KpMeeting;
use App\Models\MailboxAccount;
use App\Models\User;
use App\Services\Email\OutlookEmailService;
use App\Services\Team\OrgScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * KP CRM Calendar — merges:
 * 1) Local KP meetings
 * 2) Outlook calendar events (when a connected mailbox has Calendars.Read)
 */
class KpCalendarController extends Controller
{
    public function __construct(
        private readonly OutlookEmailService $outlook,
        private readonly OrgScopeService $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $month = $request->input('month') ?: Carbon::now('Africa/Nairobi')->format('Y-m');
        try {
            $start = Carbon::createFromFormat('Y-m', $month, 'Africa/Nairobi')->startOfMonth();
        } catch (Throwable) {
            $start = Carbon::now('Africa/Nairobi')->startOfMonth();
            $month = $start->format('Y-m');
        }
        $end = (clone $start)->endOfMonth();

        // Local meetings in range
        $meetingsQuery = KpMeeting::query()
            ->where('starts_at', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $start);
            })
            ->orderBy('starts_at');

        if (! $this->isAdmin($user)) {
            $scopeIds = $this->scope->effectiveScopeUserIds($user);
            $meetingsQuery->where(function ($w) use ($user, $scopeIds) {
                $w->whereIn('owner_user_id', $scopeIds)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id)->where('status', 'accepted'));
            });
        }

        $local = $meetingsQuery->get()->map(fn (KpMeeting $m) => [
            'id' => 'local-'.$m->id,
            'source' => 'orderwatch',
            'title' => $m->title,
            'starts_at' => $m->starts_at?->toIso8601String(),
            'ends_at' => $m->ends_at?->toIso8601String(),
            'location' => $m->location,
            'customer_acumatica_id' => $m->customer_acumatica_id,
            'customer_name' => $m->customer_name,
            'status' => $m->status,
            'notes' => $m->notes,
        ])->values()->all();

        $outlook = $this->fetchOutlookEvents($start, $end);

        $events = collect([...$local, ...($outlook['events'] ?? [])])
            ->sortBy('starts_at')
            ->values()
            ->all();

        return response()->json([
            'month' => $month,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'events' => $events,
            'outlook' => [
                'connected' => (bool) ($outlook['connected'] ?? false),
                'mailbox' => $outlook['mailbox'] ?? null,
                'error' => $outlook['error'] ?? null,
                'hint' => $outlook['hint'] ?? null,
            ],
        ]);
    }

    /**
     * @return array{connected: bool, events: list<array<string, mixed>>, mailbox: ?string, error: ?string, hint: ?string}
     */
    private function fetchOutlookEvents(Carbon $start, Carbon $end): array
    {
        $account = MailboxAccount::query()
            ->where('status', 'connected')
            ->orderByDesc('last_synced_at')
            ->first();

        if (! $account) {
            return [
                'connected' => false,
                'events' => [],
                'mailbox' => null,
                'error' => null,
                'hint' => 'Connect an Outlook mailbox in Administration → Mailboxes, then reconnect with Calendars.Read to sync Outlook events.',
            ];
        }

        try {
            $token = $this->outlook->getDecryptedToken($account);
            $url = 'https://graph.microsoft.com/v1.0/me/calendarView'
                .'?startDateTime='.urlencode($start->copy()->utc()->toIso8601String())
                .'&endDateTime='.urlencode($end->copy()->utc()->toIso8601String())
                .'&$select=id,subject,start,end,location,bodyPreview,isAllDay,organizer,webLink'
                .'&$orderby=start/dateTime'
                .'&$top=100';

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($url);

            if ($response->status() === 403 || $response->status() === 401) {
                return [
                    'connected' => true,
                    'events' => [],
                    'mailbox' => $account->email,
                    'error' => 'Outlook calendar permission missing.',
                    'hint' => 'Reconnect the mailbox so Azure consent includes Calendars.Read (current token is mail-only).',
                ];
            }

            if (! $response->successful()) {
                return [
                    'connected' => true,
                    'events' => [],
                    'mailbox' => $account->email,
                    'error' => 'Outlook calendar fetch failed (HTTP '.$response->status().').',
                    'hint' => null,
                ];
            }

            $events = collect($response->json('value', []))->map(function (array $ev) {
                $startRaw = $ev['start']['dateTime'] ?? null;
                $endRaw = $ev['end']['dateTime'] ?? null;

                return [
                    'id' => 'outlook-'.($ev['id'] ?? uniqid()),
                    'source' => 'outlook',
                    'title' => $ev['subject'] ?? '(No subject)',
                    'starts_at' => $startRaw ? Carbon::parse($startRaw)->toIso8601String() : null,
                    'ends_at' => $endRaw ? Carbon::parse($endRaw)->toIso8601String() : null,
                    'location' => $ev['location']['displayName'] ?? null,
                    'customer_acumatica_id' => null,
                    'customer_name' => null,
                    'status' => 'outlook',
                    'notes' => $ev['bodyPreview'] ?? null,
                    'web_link' => $ev['webLink'] ?? null,
                ];
            })->values()->all();

            return [
                'connected' => true,
                'events' => $events,
                'mailbox' => $account->email,
                'error' => null,
                'hint' => null,
            ];
        } catch (Throwable $e) {
            return [
                'connected' => true,
                'events' => [],
                'mailbox' => $account->email,
                'error' => $e->getMessage(),
                'hint' => 'Try reconnecting Outlook in Administration if the token expired.',
            ];
        }
    }

    private function ensureCanView(?User $user): void
    {
        if ($user === null) {
            abort(401);
        }
        if ($user->role === 'Administrator' || $user->is_super_admin
            || $user->hasPermission('kp.fol.view')
            || $user->hasPermission('kp.accounts.view')) {
            return;
        }
        abort(403, 'You do not have access to KP Calendar.');
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === 'Administrator' || (bool) $user->is_super_admin;
    }
}
