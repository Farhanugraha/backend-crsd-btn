<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Menu;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Get all user carts with restaurant data
     * Only return carts that have items
     */
    public function getCart()
    {
        $user = JWTAuth::parseToken()->authenticate();

        // Delete empty carts first
        Cart::where('user_id', $user->id)
            ->whereDoesntHave('items')
            ->delete();

        // Get carts dengan items
        $carts = Cart::with('items.menu', 'restaurant')
            ->where('user_id', $user->id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $carts
        ]);
    }

    /**
     * Add item to cart with optional notes
     */
        public function addItem(Request $request) {
        $user = JWTAuth::parseToken()->authenticate();

        $validator = Validator::make($request->all(), [
            'menu_id' => 'required|exists:menus,id',
            'restaurant_id' => 'required|exists:restaurants,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::transaction(function () use ($request, $user) {
            $menu = Menu::findOrFail($request->menu_id);

            $cart = Cart::firstOrCreate([
                'user_id' => $user->id,
                'restaurant_id' => $request->restaurant_id
            ]);
            $item = CartItem::where('cart_id', $cart->id)
                ->where('menu_id', $menu->id)
                ->where('notes', $request->notes ?? null) 
                ->first();

            if ($item) {
                $item->increment('quantity', $request->quantity);
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'menu_id' => $menu->id,
                    'quantity' => $request->quantity,
                    'price' => $menu->price,
                    'notes' => $request->notes ?? null
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart'
        ], 201);
    }
    

    /**
     * Update cart item quantity and notes
     */
    public function updateItem(Request $request, $id) {
        $user = JWTAuth::parseToken()->authenticate();

        $validator = Validator::make($request->all(), [
            'quantity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:200'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $item = CartItem::whereHas('cart', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->findOrFail($id);

            $updateData = [];

            if ($request->has('quantity') && $request->quantity) {
                $updateData['quantity'] = $request->quantity;
            }

            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes ?? null;
            }

            if (!empty($updateData)) {
                $item->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cart item updated',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found'
            ], 404);
            }
        }

    /**
     * Remove item from cart
     * If cart becomes empty, delete the cart
     */
    public function removeItem($id)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $item = CartItem::whereHas('cart', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->findOrFail($id);

        $cartId = $item->cart_id;
        $item->delete();

        // Delete cart if it has no items
        Cart::where('id', $cartId)
            ->whereDoesntHave('items')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed'
        ]);
    }

    /**
     * Clear all carts
     */
    public function clearCart()
    {
        $user = JWTAuth::parseToken()->authenticate();

        // Delete all cart items for user
        CartItem::whereHas('cart', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->delete();

        // Delete all empty carts for user
        Cart::where('user_id', $user->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared'
        ]);
    }
}