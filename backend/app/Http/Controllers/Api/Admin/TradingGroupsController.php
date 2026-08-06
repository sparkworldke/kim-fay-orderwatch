<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TradingGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TradingGroupsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => TradingGroup::query()->withCount('products')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150', 'unique:trading_groups,name'],
            'is_active' => ['nullable', 'boolean']]);
        return response()->json(TradingGroup::create($data + ['source' => 'manual']), 201);
    }

    public function update(Request $request, TradingGroup $tradingGroup): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150', Rule::unique('trading_groups')->ignore($tradingGroup)],
            'is_active' => ['sometimes', 'boolean']]);
        $tradingGroup->update($data + ['source' => 'manual']);
        return response()->json($tradingGroup->fresh()->loadCount('products'));
    }

    public function destroy(TradingGroup $tradingGroup): JsonResponse
    {
        abort_if($tradingGroup->products()->exists(), 422, 'This trading group is assigned to products and cannot be deleted.');
        $tradingGroup->delete();
        return response()->json(['message' => 'Trading group deleted.']);
    }
}
