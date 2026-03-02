<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; // TAMBAHKAN INI
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
            Log::error('Failed to retrieve menus: ' . $e->getMessage());
            
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
            Log::error('Failed to retrieve menu: ' . $e->getMessage());
            
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
                'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048'
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
                Log::error('Failed to save file: ' . $filename);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to save file'
                ], 500);
            }
            
            Log::info('File uploaded successfully: ' . $filename);
            
            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => [
                    'filename' => $filename,
                    'url' => Storage::url('uploads/' . $filename)
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Upload failed: ' . $e->getMessage());
            
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
                'price' => 'required|numeric|min:0|max:999999999',
                'image' => 'nullable|string',
                'is_available' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Hanya ambil field yang diizinkan
            $data = [
                'restaurant_id' => $request->restaurant_id,
                'name' => $request->name,
                'price' => (int) $request->price, // Cast ke integer
                'image' => $request->image ?? null,
                'is_available' => $request->has('is_available') ? (bool) $request->is_available : true
            ];

            // Log untuk debugging
            Log::info('Creating menu with data:', $data);

            $menu = Menu::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Menu created successfully',
                'data' => $menu
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Failed to create menu: ' . $e->getMessage());
            
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
                'price' => 'sometimes|numeric|min:0|max:999999999',
                'image' => 'nullable|string',
                'is_available' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Hanya ambil field yang diizinkan
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('price')) {
                $updateData['price'] = (int) $request->price; // Cast ke integer
            }
            
            if ($request->has('image')) {
                // Store old image name untuk delete nanti
                $oldImage = $menu->image;
                $updateData['image'] = $request->image;
                
                // Delete old image if image is updated
                if ($oldImage && $request->image !== $oldImage) {
                    if ($oldImage && Storage::disk('public')->exists('uploads/' . $oldImage)) {
                        Storage::disk('public')->delete('uploads/' . $oldImage);
                        Log::info('Deleted old image: ' . $oldImage);
                    }
                }
            }
            
            if ($request->has('is_available')) {
                $updateData['is_available'] = (bool) $request->is_available;
            }

            // Log untuk debugging
            Log::info('Updating menu ' . $id . ' with data:', $updateData);

            $menu->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Menu updated successfully',
                'data' => $menu
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Failed to update menu: ' . $e->getMessage());
            
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
                Log::info('Deleted image: ' . $menu->image);
            }

            $menu->delete();

            Log::info('Menu deleted successfully: ' . $id);

            return response()->json([
                'success' => true,
                'message' => 'Menu deleted successfully'
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Failed to delete menu: ' . $e->getMessage());
            
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
                'is_available' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $menu->update([
                'is_available' => (bool) $request->is_available
            ]);

            Log::info('Availability toggled for menu ' . $id . ': ' . ($request->is_available ? 'true' : 'false'));

            return response()->json([
                'success' => true,
                'message' => 'Availability toggled successfully',
                'data' => $menu
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Failed to toggle availability: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}