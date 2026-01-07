<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AreaController extends Controller
{
    /**
     * Display a listing of all areas (Public)
     * GET /api/areas
     */
    public function index()
    {
        try {
            $areas = Area::withCount('restaurants')
                ->orderBy('order', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Areas retrieved successfully',
                'data' => $areas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve areas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a specific area with restaurants (Public)
     * GET /api/areas/{id}
     */
    public function show($id)
    {
        try {
            $area = Area::with(['restaurants' => function ($query) {
                $query->where('is_open', true)
                    ->withCount('menus');
            }])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Area retrieved successfully',
                'data' => $area
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Area not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get area by slug (Public)
     * GET /api/areas/slug/{slug}
     */
    public function showBySlug($slug)
    {
        try {
            $area = Area::where('slug', $slug)
                ->with(['restaurants' => function ($query) {
                    $query->where('is_open', true)
                        ->withCount('menus');
                }])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Area retrieved successfully',
                'data' => $area
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Area not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get restaurants by area (Public)
     * GET /api/areas/{id}/restaurants
     */
    public function getRestaurants($id)
    {
        try {
            $area = Area::findOrFail($id);
            
            $restaurants = $area->restaurants()
                ->where('is_open', true)
                ->withCount('menus')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Restaurants retrieved successfully',
                'data' => [
                    'area' => $area,
                    'restaurants' => $restaurants
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Area not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Store a new area (Superadmin only)
     * POST /api/areas
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:areas,name',
            'slug' => 'nullable|string|max:255|unique:areas,slug',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();
            
            // Auto generate slug if not provided
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            $area = Area::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Area created successfully',
                'data' => $area
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create area',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an area (Superadmin only)
     * PUT /api/areas/{id}
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:areas,name,' . $id,
            'slug' => 'nullable|string|max:255|unique:areas,slug,' . $id,
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $area = Area::findOrFail($id);
            $data = $validator->validated();

            // Auto generate slug if name changed but slug not provided
            if (isset($data['name']) && empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            $area->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Area updated successfully',
                'data' => $area
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update area',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an area (Superadmin only)
     * DELETE /api/areas/{id}
     */
    public function destroy($id)
    {
        try {
            $area = Area::findOrFail($id);

            // Check if area has restaurants
            if ($area->restaurants()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete area with existing restaurants'
                ], 400);
            }

            $area->delete();

            return response()->json([
                'success' => true,
                'message' => 'Area deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete area',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}