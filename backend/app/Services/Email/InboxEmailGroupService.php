<?php

namespace App\Services\Email;

use App\Models\AcumaticaCustomer;
use App\Models\Email;
use App\Models\EmailImportConfig;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class InboxEmailGroupService
{
    private const GROUP_BY_DOMAIN = 'domain';
    private const GROUP_BY_CUSTOMER = 'customer';

    private const GENERIC_PROVIDER_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'yahoo.com',
        'icloud.com',
    ];

    public function build(Request $request): array
    {
        $query = $this->baseQuery($request);
        $stats = $this->stats($query);
        $groupBy = $request->string('group_by')->toString() === self::GROUP_BY_CUSTOMER
            ? self::GROUP_BY_CUSTOMER
            : self::GROUP_BY_DOMAIN;

        $emails = (clone $query)
            ->with([
                'matchedCustomer:id,acumatica_id,name',
                'emailImportConfig:id,display_name,customer_id',
                'emailImportConfig.customer:id,acumatica_id,name',
                'mailboxFolder.customer:id,acumatica_id,name',
                'mailboxFolder.rules.customer:id,acumatica_id,name',
            ])
            ->orderByDesc('received_at')
            ->limit(500)
            ->get();

        $grouped = [];
        foreach ($emails as $email) {
            $group = $groupBy === self::GROUP_BY_CUSTOMER
                ? $this->customerGroup($email)
                : $this->domainGroup($email);
            $key = $group['group_key'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = $group + [
                    'email_count' => 0,
                    'attachment_count' => 0,
                    'with_po_count' => 0,
                    'po_processing_count' => 0,
                    'needs_review_count' => 0,
                    'stored_non_order_count' => 0,
                    'unread_count' => 0,
                    'emails' => [],
                ];
            }

            $grouped[$key]['email_count']++;
            if ($this->hasPo($email)) {
                $grouped[$key]['with_po_count']++;
            }
            if ((bool) $email->has_attachments) {
                $grouped[$key]['attachment_count']++;
            }
            if ($email->ingestion_classification === 'po_processing') {
                $grouped[$key]['po_processing_count']++;
            }
            if ($email->ingestion_classification === 'needs_review') {
                $grouped[$key]['needs_review_count']++;
            }
            if ($email->ingestion_classification === 'stored_non_order') {
                $grouped[$key]['stored_non_order_count']++;
            }
            if (! $email->is_read) {
                $grouped[$key]['unread_count']++;
            }

            $grouped[$key]['emails'][] = $this->presentEmail($email);
        }

        $groups = collect($grouped)
            ->sortByDesc(fn (array $group) => $group['group_key'] === 'unknown-domain' || $group['group_key'] === 'unassigned' ? -1 : $group['email_count'])
            ->values()
            ->all();

        return [
            'stats' => $stats,
            'groups' => $groups,
            'group_by' => $groupBy,
            'truncated' => $stats['total'] > $emails->count(),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
    }

    private function baseQuery(Request $request): Builder
    {
        $query = Email::query();

        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_account_id', $request->integer('mailbox_id'));
        }

        if ($request->filled('search')) {
            $term = '%'.strtolower($request->string('search')->trim()).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(subject) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(from_email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(from_name) LIKE ?', [$term]);
            });
        }

        if ($request->filled('date_from')) {
            $query->where('received_at', '>=', Carbon::parse($request->string('date_from'))->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('received_at', '<=', Carbon::parse($request->string('date_to'))->endOfDay());
        }

        return $query;
    }

    /** @return array{total:int,with_po:int,po_processing:int,needs_review:int,stored_non_order:int,unread:int} */
    private function stats(Builder $query): array
    {
        $base = clone $query;

        return [
            'total' => (clone $base)->count(),
            'with_po' => (clone $base)->where(function ($q) {
                $q->whereNotNull('extracted_po_number')
                    ->orWhereNotNull('canonical_po')
                    ->orWhere('ingestion_classification', 'po_processing');
            })->count(),
            'po_processing' => (clone $base)->where('ingestion_classification', 'po_processing')->count(),
            'needs_review' => (clone $base)->where('ingestion_classification', 'needs_review')->count(),
            'stored_non_order' => (clone $base)->where('ingestion_classification', 'stored_non_order')->count(),
            'unread' => (clone $base)->where('is_read', false)->count(),
        ];
    }

    private function resolveCustomer(Email $email): ?AcumaticaCustomer
    {
        if ($email->matchedCustomer) {
            return $email->matchedCustomer;
        }

        if ($email->emailImportConfig?->customer) {
            return $email->emailImportConfig->customer;
        }

        $folder = $email->mailboxFolder;
        if (! $folder) {
            return null;
        }

        if ($folder->customer) {
            return $folder->customer;
        }

        foreach ($folder->rules as $rule) {
            if ($rule->customer) {
                return $rule->customer;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function customerGroup(Email $email): array
    {
        $customer = $this->resolveCustomer($email);
        $key = $customer ? 'customer-'.$customer->id : 'unassigned';
        $label = $customer?->name ?? 'Unassigned';

        return [
            'group_key' => $key,
            'group_type' => self::GROUP_BY_CUSTOMER,
            'group_label' => $label,
            'domain' => null,
            'customer_id' => $customer?->id,
            'customer_name' => $label,
            'acumatica_id' => $customer?->acumatica_id,
        ];
    }

    /** @return array<string, mixed> */
    private function domainGroup(Email $email): array
    {
        $domain = $this->senderDomain($email->from_email);

        if ($domain === null) {
            return [
                'group_key' => 'unknown-domain',
                'group_type' => self::GROUP_BY_DOMAIN,
                'group_label' => 'Unknown domain',
                'domain' => null,
                'customer_id' => null,
                'customer_name' => 'Unknown domain',
                'acumatica_id' => null,
            ];
        }

        if (in_array($domain, self::GENERIC_PROVIDER_DOMAINS, true)) {
            return [
                'group_key' => 'domain-'.$domain,
                'group_type' => self::GROUP_BY_DOMAIN,
                'group_label' => $domain,
                'domain' => $domain,
                'customer_id' => null,
                'customer_name' => $domain,
                'acumatica_id' => null,
            ];
        }

        $customer = $this->resolveCustomer($email);
        $label = $customer?->name
            ?? $this->emailImportConfigLabel($email->emailImportConfig)
            ?? $domain;

        return [
            'group_key' => $customer ? 'customer-domain-'.$customer->id.'-'.$domain : 'domain-'.$domain,
            'group_type' => self::GROUP_BY_DOMAIN,
            'group_label' => $label,
            'domain' => $domain,
            'customer_id' => $customer?->id,
            'customer_name' => $label,
            'acumatica_id' => $customer?->acumatica_id,
        ];
    }

    private function senderDomain(?string $senderEmail): ?string
    {
        $senderEmail = strtolower(trim((string) $senderEmail));
        if ($senderEmail === '') {
            return null;
        }

        $atPos = strrpos($senderEmail, '@');
        if ($atPos === false || $atPos === strlen($senderEmail) - 1) {
            return null;
        }

        $domain = substr($senderEmail, $atPos + 1);
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return null;
        }

        return $domain;
    }

    private function emailImportConfigLabel(?EmailImportConfig $config): ?string
    {
        $label = trim((string) ($config?->display_name ?? ''));

        return $label !== '' ? $label : null;
    }

    private function hasPo(Email $email): bool
    {
        if ($email->extracted_po_number || $email->canonical_po) {
            return true;
        }

        return $email->ingestion_classification === 'po_processing';
    }

    private function presentEmail(Email $email): array
    {
        $folder = $email->mailboxFolder;

        return [
            'id' => $email->id,
            'mailbox_account_id' => $email->mailbox_account_id,
            'message_id' => $email->message_id,
            'subject' => $email->subject,
            'from_email' => $email->from_email,
            'from_name' => $email->from_name,
            'body_preview' => $email->body_preview,
            'has_attachments' => (bool) $email->has_attachments,
            'is_read' => $email->is_read,
            'received_at' => $email->received_at,
            'folder' => $email->folder,
            'matched_branch_tag' => $email->matched_branch_tag,
            'import_guardrail_status' => $email->import_guardrail_status,
            'import_guardrail_reason' => $email->import_guardrail_reason,
            'ingestion_classification' => $email->ingestion_classification,
            'ingestion_reason_codes' => $email->ingestion_reason_codes,
            'extracted_po_number' => $email->extracted_po_number,
            'canonical_po' => $email->canonical_po,
            'email_import_config' => $email->emailImportConfig ? [
                'id' => $email->emailImportConfig->id,
                'display_name' => $email->emailImportConfig->display_name,
            ] : null,
            'mailbox_folder' => $folder ? [
                'display_name' => $folder->display_name,
                'customer' => $folder->customer ? [
                    'id' => $folder->customer->id,
                    'acumatica_id' => $folder->customer->acumatica_id,
                    'name' => $folder->customer->name,
                ] : null,
                'rules' => $folder->rules->map(fn ($rule) => [
                    'id' => $rule->id,
                    'existing_rule_name' => $rule->existing_rule_name,
                    'customer_id' => $rule->customer_id,
                    'is_enabled' => $rule->is_enabled,
                    'is_trusted' => $rule->is_trusted,
                    'notes' => $rule->notes,
                ])->values()->all(),
            ] : null,
        ];
    }
}
