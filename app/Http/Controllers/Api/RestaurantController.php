<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RestaurantController extends Controller
{
    /**
     * Get all restaurants
     */
    public function index(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);

            $restaurants = Restaurant::paginate($perPage, ['*'], 'page', $page);

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
     * Get restaurant detail with menus
     */
    public function show($id)
    {
        try {
            $restaurant = Restaurant::with('menus')->find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
            }

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
     * Create restaurant (admin only)
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'address' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $restaurant = Restaurant::create($request->all());

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
     * Update restaurant (admin only)
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
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'address' => 'sometimes|required|string|max:255',
                'is_open' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $restaurant->update($request->all());

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
     * Delete restaurant (admin only)
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
}