<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class RestaurantController extends Controller
{
    /**
     * Display a listing of restaurants
     * GET /api/restaurants?page=1&per_page=10
     * 
     * Public: hanya restoran buka
     * Admin/Superadmin: semua restoran
     */
    public function index(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);
            $areaId = $request->get('area_id');
            $user = Auth::user();

            $query = Restaurant::with('area');

            // Jika user bukan admin/superadmin, hanya tampilkan yang buka
            if (!$user || !in_array($user->role, ['admin', 'superadmin'])) {
                $query->where('is_open', true);
            }

            // Filter by area_id if provided
            if ($areaId) {
                $query->where('area_id', $areaId);
            }

            // Add menu count
            $query->withCount('menus');

            // Order by latest
            $restaurants = $query->latest()->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Restaurants retrieved successfully',
                'data' => $restaurants
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve restaurants',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get restaurants by area (Public - hanya yang buka)
     * GET /api/restaurants/area/{areaId}
     */
    public function getByArea($areaId)
    {
        try {
            // Validate area exists
            $area = Area::findOrFail($areaId);
            
            $restaurants = Restaurant::where('area_id', $areaId)
                ->with('area')
                ->withCount('menus')
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Restaurants retrieved successfully',
                'data' => [
                    'area' => $area,
                    'restaurants' => $restaurants,
                    'total' => $restaurants->count()
                ]
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Area not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve restaurants',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a specific restaurant with menus (Public)
     * GET /api/restaurants/{id}
     */
    public function show($id)
    {
        try {
            $restaurant = Restaurant::with([
                'area',
                'menus' => function ($query) {
                    $query->where('is_available', true)
                          ->orderBy('name', 'asc');
                }
            ])->find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
                }

                // Add menus count
                $restaurant->loadCount('menus');

                return response()->json([
                'success' => true,
                'message' => 'Restaurant retrieved successfully',
                'data' => $restaurant
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve restaurant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new restaurant (Superadmin only)
     * POST /api/restaurants
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'area_id' => 'required|exists:areas,id',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'address' => 'required|string|max:500',
                'is_open' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            
            // Set default is_open if not provided
            if (!isset($data['is_open'])) {
                $data['is_open'] = true;
            }

            $restaurant = Restaurant::create($data);
            $restaurant->load('area');
            $restaurant->loadCount('menus');

            return response()->json([
                'success' => true,
                'message' => 'Restaurant created successfully',
                'data' => $restaurant
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create restaurant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a restaurant (Superadmin only)
     * PUT /api/restaurants/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $restaurant = Restaurant::find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'area_id' => 'sometimes|required|exists:areas,id',
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'address' => 'sometimes|required|string|max:500',
                'is_open' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $restaurant->update($validator->validated());
            $restaurant->load('area');
            $restaurant->loadCount('menus');

            return response()->json([
                'success' => true,
                'message' => 'Restaurant updated successfully',
                'data' => $restaurant
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update restaurant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle restaurant open/close status (Superadmin only)
     * PATCH /api/restaurants/{id}/toggle-status
     */
    public function toggleStatus($id)
    {
        try {
            $restaurant = Restaurant::find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
            }

            // Toggle is_open
            $restaurant->is_open = !$restaurant->is_open;
            $restaurant->save();
            
            // Refresh data dengan relasi
            $restaurant->load('area');
            $restaurant->loadCount('menus');

            return response()->json([
                'success' => true,
                'message' => 'Restaurant status updated successfully',
                'data' => $restaurant
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update restaurant status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a restaurant (Superadmin only)
     * DELETE /api/restaurants/{id}
     */
    public function destroy($id)
    {
        try {
            $restaurant = Restaurant::find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
            }

            // Check if restaurant has menus
            if ($restaurant->menus()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete restaurant with existing menus. Please delete all menus first.'
                ], 400);
            }

            // Check if restaurant has active carts
            if ($restaurant->carts()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete restaurant with active carts. Please clear all carts first.'
                ], 400);
            }

            $restaurant->delete();

            return response()->json([
                'success' => true,
                'message' => 'Restaurant deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete restaurant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get restaurant statistics (Superadmin only)
     * GET /api/restaurants/{id}/stats
     */
    public function getStats($id)
    {
        try {
            $restaurant = Restaurant::with('area')->find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
            }

            $stats = [
                'total_menus' => $restaurant->menus()->count(),
                'available_menus' => $restaurant->menus()->where('is_available', true)->count(),
                'unavailable_menus' => $restaurant->menus()->where('is_available', false)->count(),
                'total_carts' => $restaurant->carts()->count(),
                'is_open' => (bool) $restaurant->is_open
            ];

            return response()->json([
                'success' => true,
                'message' => 'Restaurant statistics retrieved successfully',
                'data' => [
                    'restaurant' => $restaurant,
                    'stats' => $stats
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve restaurant statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search restaurants by name (Public - hanya yang buka)
     * GET /api/restaurants/search?q=nama&area_id=1
     */
    public function search(Request $request)
    {
        try {
            $query = $request->get('q');
            $areaId = $request->get('area_id');

            if (empty($query)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search query is required'
                ], 400);
            }

            $restaurantsQuery = Restaurant::with('area')
                ->where('is_open', true)
                ->where('name', 'LIKE', "%{$query}%");

            // Filter by area if provided
            if ($areaId) {
                $restaurantsQuery->where('area_id', $areaId);
            }

            $restaurants = $restaurantsQuery->withCount('menus')->latest()->get();

            return response()->json([
                'success' => true,
                'message' => 'Search results retrieved successfully',
                'data' => $restaurants,
                'total' => $restaurants->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search restaurants',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}