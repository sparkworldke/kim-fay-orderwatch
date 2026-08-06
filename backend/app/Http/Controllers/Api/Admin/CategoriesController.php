<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Category::query()->with('parent:id,name')->withCount(['children'])
            ->orderByRaw('parent_id IS NOT NULL')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'], 'is_active' => ['nullable', 'boolean']]);
        $exists = Category::query()->where('name', $data['name'])->where('parent_id', $data['parent_id'] ?? null)->exists();
        abort_if($exists, 422, 'This category already exists at that level.');
        return response()->json(Category::create($data + ['source' => 'manual']), 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('id', '!=', $category->id))],
            'is_active' => ['sometimes', 'boolean']]);
        $category->update($data + ['source' => 'manual']);
        return response()->json($category->fresh()->load('parent:id,name'));
    }

    public function destroy(Category $category): JsonResponse
    {
        abort_if($category->children()->exists() || $category->products()->exists(), 422, 'This category is in use and cannot be deleted.');
        $category->delete();
        return response()->json(['message' => 'Category deleted.']);
    }
}
