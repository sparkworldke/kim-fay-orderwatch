<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Services\Crm\CustomerContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerContactController extends Controller
{
    public function __construct(
        private readonly CustomerContactService $contacts,
    ) {}

    public function designations(): JsonResponse
    {
        return response()->json([
            'designations' => $this->contacts->designationOptions(),
        ]);
    }

    public function index(Request $request, string $customerId): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive');
        $rows = $this->contacts->listForCustomer(
            $request->user(),
            $customerId,
            activeOnly: ! $includeInactive,
        );

        return response()->json([
            'data' => $rows->map->toApiArray()->values(),
            'designations' => $this->contacts->designationOptions(),
        ]);
    }

    public function store(Request $request, string $customerId): JsonResponse
    {
        $validated = $this->validateContact($request);
        $contact = $this->contacts->create($request->user(), $customerId, $validated);

        return response()->json($contact->toApiArray(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contact = CustomerContact::query()->findOrFail($id);
        $validated = $this->validateContact($request, partial: true);
        $contact = $this->contacts->update($request->user(), $contact, $validated);

        return response()->json($contact->toApiArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $contact = CustomerContact::query()->findOrFail($id);
        $contact = $this->contacts->deactivate($request->user(), $contact);

        return response()->json($contact->toApiArray());
    }

    /** @return array<string, mixed> */
    private function validateContact(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'designation_key' => [$required, 'string', Rule::in(array_keys(CustomerContact::DESIGNATIONS))],
            'designation_label' => ['nullable', 'string', 'max:120'],
            'first_name' => [$required, 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
