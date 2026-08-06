<?php

namespace App\Services\Crm;

use App\Models\AcumaticaCustomer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Support\DataScope;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerContactService
{
    /** @return list<array{key: string, label: string}> */
    public function designationOptions(): array
    {
        return collect(CustomerContact::DESIGNATIONS)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    public function ensureCustomerAccessible(User $actor, string $customerId): AcumaticaCustomer
    {
        $customer = AcumaticaCustomer::query()->where('acumatica_id', $customerId)->firstOrFail();
        if (! DataScope::customerAccessible($actor, $customer->acumatica_id, $customer->customer_class)) {
            abort(403, 'Customer is outside your accessible portfolio.');
        }

        return $customer;
    }

    /** @return Collection<int, CustomerContact> */
    public function listForCustomer(User $actor, string $customerId, bool $activeOnly = true): Collection
    {
        $this->ensureCustomerAccessible($actor, $customerId);

        $query = CustomerContact::query()
            ->where('customer_acumatica_id', $customerId)
            ->orderByDesc('is_primary')
            ->orderBy('designation_key')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * @param  array{
     *   designation_key: string,
     *   designation_label?: ?string,
     *   first_name: string,
     *   last_name: string,
     *   phone?: ?string,
     *   email?: ?string,
     *   is_primary?: bool,
     *   notes?: ?string
     * }  $data
     */
    public function create(User $actor, string $customerId, array $data): CustomerContact
    {
        $this->ensureCustomerAccessible($actor, $customerId);
        $payload = $this->normalizePayload($data);
        if (! CustomerContact::query()->active()->where('customer_acumatica_id', $customerId)->where('is_primary', true)->exists()) {
            $payload['is_primary'] = true;
        }

        $contact = CustomerContact::create([
            ...$payload,
            'customer_acumatica_id' => $customerId,
            'is_active' => true,
            'created_by_user_id' => $actor->id,
        ]);

        if ($contact->is_primary) {
            $this->clearOtherPrimaries($customerId, $contact->id);
        }

        return $contact->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, CustomerContact $contact, array $data): CustomerContact
    {
        $this->ensureCustomerAccessible($actor, $contact->customer_acumatica_id);
        $payload = $this->normalizePayload(array_merge([
            'designation_key' => $contact->designation_key,
            'designation_label' => $contact->designation_label,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_primary' => $contact->is_primary,
            'notes' => $contact->notes,
        ], $data));

        if (! $payload['is_primary'] && ! CustomerContact::query()
            ->active()
            ->where('customer_acumatica_id', $contact->customer_acumatica_id)
            ->whereKeyNot($contact->id)
            ->where('is_primary', true)
            ->exists()) {
            $payload['is_primary'] = true;
        }

        $contact->forceFill($payload)->save();

        if ($contact->is_primary) {
            $this->clearOtherPrimaries($contact->customer_acumatica_id, $contact->id);
        }

        return $contact->fresh();
    }

    /** Ensure at most one primary contact per account. */
    private function clearOtherPrimaries(string $customerId, int $keepId): void
    {
        CustomerContact::query()
            ->where('customer_acumatica_id', $customerId)
            ->where('id', '!=', $keepId)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }

    public function deactivate(User $actor, CustomerContact $contact): CustomerContact
    {
        $this->ensureCustomerAccessible($actor, $contact->customer_acumatica_id);
        $contact->forceFill(['is_active' => false, 'is_primary' => false])->save();

        CustomerContact::query()
            ->active()
            ->where('customer_acumatica_id', $contact->customer_acumatica_id)
            ->orderBy('id')
            ->first()?->forceFill(['is_primary' => true])->save();

        return $contact->fresh();
    }

    /**
     * Resolve contact for FOL: load by id, or create from requestor fields when save flag set.
     *
     * @param  array<string, mixed>  $data
     * @return array{contact: ?CustomerContact, first_name: string, last_name: string, phone: string, email: string}
     */
    public function resolveRequestorForFol(User $actor, string $customerId, array $data): array
    {
        $this->ensureCustomerAccessible($actor, $customerId);

        $contact = null;
        $contactId = $data['requestor_contact_id'] ?? null;

        if ($contactId) {
            $contact = CustomerContact::query()
                ->active()
                ->whereKey($contactId)
                ->where('customer_acumatica_id', $customerId)
                ->first();
            if (! $contact) {
                throw ValidationException::withMessages([
                    'requestor_contact_id' => ['Selected contact was not found on this account.'],
                ]);
            }
        } elseif (! empty($data['save_requestor_as_contact'])) {
            $contact = $this->create($actor, $customerId, [
                'designation_key' => $data['requestor_designation_key'] ?? CustomerContact::DESIGNATION_CUSTOM,
                'designation_label' => $data['requestor_designation_label'] ?? null,
                'first_name' => $data['requestor_first_name'],
                'last_name' => $data['requestor_last_name'],
                'phone' => $data['requestor_phone'] ?? null,
                'email' => $data['requestor_email'] ?? null,
                'is_primary' => (bool) ($data['requestor_is_primary'] ?? false),
            ]);
        }

        if ($contact) {
            return [
                'contact' => $contact,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'phone' => (string) ($contact->phone ?? $data['requestor_phone'] ?? ''),
                'email' => (string) ($contact->email ?? $data['requestor_email'] ?? ''),
            ];
        }

        return [
            'contact' => null,
            'first_name' => (string) $data['requestor_first_name'],
            'last_name' => (string) $data['requestor_last_name'],
            'phone' => (string) $data['requestor_phone'],
            'email' => (string) $data['requestor_email'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data): array
    {
        $key = (string) ($data['designation_key'] ?? '');
        if (! array_key_exists($key, CustomerContact::DESIGNATIONS)) {
            throw ValidationException::withMessages([
                'designation_key' => ['Invalid designation. Use a predefined role or custom.'],
            ]);
        }

        $label = trim((string) ($data['designation_label'] ?? ''));
        if ($key === CustomerContact::DESIGNATION_CUSTOM) {
            if ($label === '') {
                throw ValidationException::withMessages([
                    'designation_label' => ['Custom designation requires a label (job title).'],
                ]);
            }
        } else {
            $label = CustomerContact::DESIGNATIONS[$key];
        }

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $errors = [];
        if ($first === '') {
            $errors['first_name'] = ['First name is required.'];
        }
        if ($last === '') {
            $errors['last_name'] = ['Last name is required.'];
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($phone === '' && $email === '') {
            $errors['contact_method'] = ['Add at least an email address or phone number.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'designation_key' => $key,
            'designation_label' => $label,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            'notes' => isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : null,
        ];
    }
}
