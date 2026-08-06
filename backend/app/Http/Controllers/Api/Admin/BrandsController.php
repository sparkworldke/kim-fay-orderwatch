<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Brand::query()
            ->with('partnerGroup:id,name')
            ->withCount('products')
            ->orderBy('name')
            ->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150', 'unique:brands,name'],
            'ownership' => ['nullable', Rule::in(['manufactured', 'partner'])], 'is_active' => ['nullable', 'boolean']]);
        return response()->json(Brand::create($data + ['source' => 'manual']), 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150', Rule::unique('brands')->ignore($brand)],
            'ownership' => ['nullable', Rule::in(['manufactured', 'partner'])], 'is_active' => ['sometimes', 'boolean']]);
        $brand->update($data + ['source' => 'manual']);
        return response()->json($brand->fresh()->loadCount('products'));
    }

    public function destroy(Brand $brand): JsonResponse
    {
        abort_if($brand->products()->exists(), 422, 'This brand is assigned to products and cannot be deleted.');
        $brand->delete();
        return response()->json(['message' => 'Brand deleted.']);
    }
}
