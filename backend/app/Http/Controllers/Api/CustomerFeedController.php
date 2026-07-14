<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CustomerFeed\CustomerFeedService;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerFeedController extends Controller
{
    public function __construct(
        private readonly CustomerFeedService $feed,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'q'         => ['nullable', 'string', 'max:200'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo   = $validated['date_to'] ?? now()->toDateString();

        return response()->json(
            $this->feed->listGroups(
                $dateFrom,
                $dateTo,
                $validated['q'] ?? null,
                DataScope::scopedCustomerAcumaticaIds($request->user()),
            ),
        );
    }

    public function insights(Request $request, string $groupKey): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo   = $validated['date_to'] ?? now()->toDateString();

        return response()->json(
            $this->feed->insights(
                $groupKey,
                $dateFrom,
                $dateTo,
                DataScope::scopedCustomerAcumaticaIds($request->user()),
            ),
        );
    }
}