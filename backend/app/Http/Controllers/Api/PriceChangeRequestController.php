<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\PriceChangeRequest;
use App\Services\Pricing\PriceChangeRequestService;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PriceChangeRequestController extends Controller
{
    public function __construct(private readonly PriceChangeRequestService $pcr) {}

    public function index(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.view');

        $query = $this->pcr->listQuery($request->user(), $request->input('view'));
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($scoped) use ($q) {
                $scoped->where('public_ref', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$q}%")
                    ->orWhere('inventory_id', 'like', "%{$q}%");
            });
        }

        $page = $query->paginate($request->integer('per_page', 50));
        $page->setCollection($page->getCollection()->map(fn (PriceChangeRequest $item) => $this->pcr->present($request->user(), $item)));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $pcr = $this->pcr->create($request->user(), $validated);

        return response()->json($this->pcr->present($request->user(), $pcr), 201);
    }

    public function show(Request $request, PriceChangeRequest $priceChangeRequest): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.view');
        $this->authorizeView($request, $priceChangeRequest);

        return response()->json($this->pcr->present($request->user(), $priceChangeRequest));
    }

    public function resolvePrice(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.create');
        $validated = $request->validate([
            'customer_acumatica_id' => ['required', 'string', 'max:50'],
            'inventory_id' => ['required', 'string', 'max:100'],
            'proposed_selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->pcr->resolvePrice(
            $request->user(),
            $validated['customer_acumatica_id'],
            $validated['inventory_id'],
            isset($validated['proposed_selling_price']) ? (float) $validated['proposed_selling_price'] : null,
        ));
    }

    public function decision(Request $request, PriceChangeRequest $priceChangeRequest): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['required', 'string', 'min:2', 'max:5000'],
        ]);
        $this->authorizeView($request, $priceChangeRequest);

        $pcr = $this->pcr->decide($request->user(), $priceChangeRequest, $validated['decision'], $validated['comment']);

        return response()->json($this->pcr->present($request->user(), $pcr));
    }

    public function acknowledgeDuplicate(Request $request, PriceChangeRequest $priceChangeRequest): JsonResponse
    {
        $this->authorizeView($request, $priceChangeRequest);

        return response()->json($this->pcr->present(
            $request->user(),
            $this->pcr->acknowledgeDuplicate($request->user(), $priceChangeRequest),
        ));
    }

    public function markAppliedErp(Request $request, PriceChangeRequest $priceChangeRequest): JsonResponse
    {
        $this->authorizeView($request, $priceChangeRequest);

        return response()->json($this->pcr->present(
            $request->user(),
            $this->pcr->markAppliedErp($request->user(), $priceChangeRequest),
        ));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.view');

        return response()->json($this->pcr->dashboard($request->user()));
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.create');
        $q = trim((string) $request->input('q', ''));
        $query = DataScope::applyCustomerScope(AcumaticaCustomer::query()->orderBy('name'), $request->user());
        if ($q !== '') {
            $query->where(fn ($scoped) => $scoped
                ->where('name', 'like', "%{$q}%")
                ->orWhere('acumatica_id', 'like', "%{$q}%"));
        }

        return response()->json($query->limit(25)->get([
            'acumatica_id',
            'name',
            'customer_class',
            'payment_terms',
            'status',
        ]));
    }

    public function searchInventory(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.create');
        $q = trim((string) $request->input('q', ''));
        $query = AcumaticaInventoryItem::query()
            ->where(function ($scoped) {
                $scoped->whereNull('item_status')
                    ->orWhere('item_status', 'not like', '%Inactive%');
            })
            ->orderBy('inventory_id');
        if ($q !== '') {
            $query->where(fn ($scoped) => $scoped
                ->where('inventory_id', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%"));
        }

        return response()->json($query->limit(25)->get([
            'inventory_id',
            'description',
            'sales_price',
            'item_status',
        ]));
    }

    private function rules(): array
    {
        return [
            'customer_acumatica_id' => ['required', 'string', 'max:50'],
            'inventory_id' => ['required', 'string', 'max:100'],
            'proposed_selling_price' => ['required', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'string', 'max:10'],
            'justification' => ['required', 'string', 'min:10', 'max:5000'],
            'effective_date_requested' => ['nullable', 'date'],
        ];
    }

    private function authorizeView(Request $request, PriceChangeRequest $priceChangeRequest): void
    {
        if (! $this->pcr->listQuery($request->user())->whereKey($priceChangeRequest->id)->exists()) {
            abort(403, 'Forbidden.');
        }
    }
}
