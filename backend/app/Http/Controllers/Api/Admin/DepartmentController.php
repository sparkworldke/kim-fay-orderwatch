<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::query()
            ->orderBy('sort_order')
            ->with(['brandAssignments'])
            ->get()
            ->map(fn (Department $dept) => [
                'id' => $dept->id,
                'slug' => $dept->slug,
                'name' => $dept->name,
                'is_customer_facing' => $dept->is_customer_facing,
                'brands' => $dept->brandAssignments->pluck('brand')->values(),
            ]);

        return response()->json($departments);
    }

    public function syncBrands(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'brands' => ['required', 'array'],
            'brands.*' => ['string', 'max:100'],
        ]);

        DepartmentBrand::query()->where('department_id', $department->id)->delete();

        foreach (array_unique($validated['brands']) as $brand) {
            $brand = trim($brand);
            if ($brand === '') {
                continue;
            }
            DepartmentBrand::create([
                'department_id' => $department->id,
                'brand' => $brand,
            ]);
        }

        return response()->json([
            'message' => 'Department brands updated.',
            'brands' => $department->brandAssignments()->pluck('brand'),
        ]);
    }
}