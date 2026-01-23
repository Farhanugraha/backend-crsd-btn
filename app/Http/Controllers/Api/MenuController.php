<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    /**
     * Get menus by restaurant
     */
    public function index($restaurantId)
    {
        try {
            $menus = Menu::where('restaurant_id', $restaurantId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Menus retrieved successfully',
                'data' => $menus
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve menus',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get menu detail
     */
    public function show($id)
    {
        try {
            $menu = Menu::find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Menu retrieved successfully',
                'data' => $menu
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload menu image
     */
    public function uploadImage(Request $request)
    {
        try {
            // Validate file
            $validator = Validator::make($request->all(), [
                'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if file exists
            if (!$request->hasFile('file')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No file uploaded'
                ], 400);
            }

            $file = $request->file('file');
            
            // Generate unique filename dengan slug
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $filename = Str::slug($originalName) . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Create uploads directory if not exists
            if (!Storage::disk('public')->exists('uploads')) {
                Storage::disk('public')->makeDirectory('uploads');
            }

            // Store file
            $path = $file->storeAs('uploads', $filename, 'public');
            
            if (!$path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to save file'
                ], 500);
            }

            /**
             * PERBAIKAN DI SINI:
             * Menggunakan Storage::url() secara langsung untuk menghindari 
             * undefined method error pada Intelephense.
             */
            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => [
                    'filename' => $filename,
                    'url' => Storage::url('uploads/' . $filename)
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create menu (admin only)
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'restaurant_id' => 'required|exists:restaurants,id',
                'name' => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
                'image' => 'required|string',
                'is_available' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Prepare data
            $data = [
                'restaurant_id' => $request->restaurant_id,
                'name' => $request->name,
                'price' => $request->price,
                'image' => $request->image,
                'is_available' => $request->is_available ?? true
            ];

            $menu = Menu::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Menu created successfully',
                'data' => $menu
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update menu (admin only)
     */
    public function update(Request $request, $id)
    {
        try {
            $menu = Menu::find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'price' => 'sometimes|numeric|min:0',
                'image' => 'sometimes|string',
                'is_available' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Store old image name untuk delete nanti
            $oldImage = $menu->image;

            // Update menu
            $menu->update($request->all());

            // Delete old image if image is updated
            if ($request->has('image') && $request->image !== $oldImage) {
                if ($oldImage && Storage::disk('public')->exists('uploads/' . $oldImage)) {
                    Storage::disk('public')->delete('uploads/' . $oldImage);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Menu updated successfully',
                'data' => $menu
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete menu (admin only)
     */
    public function destroy($id)
    {
        try {
            $menu = Menu::find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            // Delete image from storage
            if ($menu->image && Storage::disk('public')->exists('uploads/' . $menu->image)) {
                Storage::disk('public')->delete('uploads/' . $menu->image);
            }

            $menu->delete();

            return response()->json([
                'success' => true,
                'message' => 'Menu deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle menu availability
     */
    public function toggleAvailability(Request $request, $id)
    {
        try {
            $menu = Menu::find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'is_available' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $menu->update(['is_available' => $request->is_available]);

            return response()->json([
                'success' => true,
                'message' => 'Availability toggled successfully',
                'data' => $menu
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}