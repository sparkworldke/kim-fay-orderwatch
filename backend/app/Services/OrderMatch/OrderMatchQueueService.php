<?php

namespace App\Services\OrderMatch;

use App\Models\AcumaticaCustomer;
use App\Models\Email;
use App\Models\MatchLog;
use App\Models\MatchPrediction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OrderMatchQueueService
{
    public function __construct(private readonly OrderMatchPoNormalizer $normalizer)
    {
    }

    public function pendingCount(): int
    {
        return $this->baseQuery('pending')->count();
    }

    public function queue(
        string $status = 'pending',
        ?string $accountId = null,
        int $page = 1,
        int $pageSize = 50,
    ): array {
        $query = $this->baseQuery($status);
        if ($accountId) {
            $query->whereHas('mailboxFolder.customer', fn ($q) => $q->where('acumatica_id', $accountId));
        }

        /** @var LengthAwarePaginator $paginated */
        $paginated = $query->paginate($pageSize, ['*'], 'page', $page);
        $emails = collect($paginated->items());
        $groups = $this->groupByAccount($emails);

        return [
            'groups'       => $groups,
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Email> */
    private function baseQuery(string $status)
    {
        $query = Email::with([
            'mailboxFolder.customer.parent',
            'matchedOrder',
            'predictions' => fn ($q) => $q->where('is_top_prediction', true),
        ])->where('po_extraction_attempted', true);

        return match ($status) {
            'accepted' => $query->where('match_status', 'accepted'),
            'rejected' => $query->where('match_status', 'rejected'),
            'all'      => $query,
            default    => $query->whereIn('match_status', ['pending', 'auto_matched', 'no_match'])
                ->whereNull('reviewer_decision'),
        };
    }

    /** @param  Collection<int, Email>  $emails */
    private function groupByAccount(Collection $emails): array
    {
        return $emails->groupBy(function (Email $email) {
            $customer = $email->mailboxFolder?->customer;
            $parentId = $customer?->parent?->acumatica_id ?? $customer?->acumatica_id ?? 'unknown';

            return $parentId;
        })->map(function (Collection $group, string $mainAccountId) {
            $firstCustomer = $group->first()?->mailboxFolder?->customer;
            $parent = $firstCustomer?->parent ?? $firstCustomer;

            return [
                'main_account_id'   => $mainAccountId,
                'main_account_name' => $parent?->name ?? $mainAccountId,
                'email_count'       => $group->count(),
                'revenue_at_risk'   => round($group->sum(fn (Email $e) => (float) ($e->matchedOrder?->order_total ?? 0)), 2),
                'emails'            => $group->map(fn (Email $e) => $this->presentEmail($e))->values()->all(),
            ];
        })->sortByDesc('revenue_at_risk')->values()->all();
    }

    private function presentEmail(Email $email): array
    {
        $top = $email->predictions->first();
        $confidence = $top ? (float) $top->confidence : null;

        return [
            'id'                 => $email->id,
            'subject'            => $email->subject,
            'from_email'         => $email->from_email,
            'from_name'          => $email->from_name,
            'body_preview'       => mb_substr($email->body_preview ?? '', 0, 255),
            'received_at'        => $email->received_at,
            'canonical_po'       => $email->canonical_po ?? $email->extracted_po_number,
            'extraction_status'  => $email->extraction_status,
            'match_status'       => $email->match_status,
            'duplicate_flag'     => $email->duplicate_flag,
            'canonical_email_id' => $email->canonical_email_id,
            'top_prediction'     => $top ? [
                'id'         => $top->id,
                'order_nbr'  => $top->order_nbr,
                'confidence' => $confidence,
                'match_type' => $top->match_type,
                'reasoning'  => $top->reasoning,
                'label'      => $this->confidenceLabel($confidence),
            ] : null,
            'customer' => [
                'id'   => $email->mailboxFolder?->customer?->acumatica_id,
                'name' => $email->mailboxFolder?->customer?->name,
            ],
        ];
    }

    private function confidenceLabel(?float $confidence): string
    {
        if ($confidence === null) {
            return 'no_match';
        }
        if ($confidence >= 0.95) {
            return 'high';
        }
        if ($confidence >= 0.75) {
            return 'medium';
        }

        return 'low';
    }

    public function auditLog(int $page = 1, int $pageSize = 50): LengthAwarePaginator
    {
        return MatchLog::with(['email:id,subject,from_email', 'acceptedByUser:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}