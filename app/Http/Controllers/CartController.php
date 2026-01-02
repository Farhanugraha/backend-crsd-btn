<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Carts_Items;
use App\Models\Menu;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    /**
     * Get user cart
     */
    public function getCart()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $cart = Cart::with('items.menu')->where('user_id', $user->id)->first();

            if (!$cart) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cart is empty',
                    'data' => null
                ], 200);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cart retrieved successfully',
                'data' => $cart
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add item to cart
     */
    public function addItem(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'menu_id' => 'required|exists:menus,id',
                'restaurant_id' => 'required|exists:restaurants,id',
                'quantity' => 'required|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $menu = Menu::find($request->menu_id);
            
            // Get or create cart for user and restaurant
            $cart = Cart::firstOrCreate(
                ['user_id' => $user->id, 'restaurant_id' => $request->restaurant_id]
            );

            // Check if item already in cart
            $cartItem = Carts_Items::where('cart_id', $cart->id)
                ->where('menu_id', $request->menu_id)
                ->first();

            if ($cartItem) {
                $cartItem->quantity += $request->quantity;
                $cartItem->save();
            } else {
                Carts_Items::create([
                    'cart_id' => $cart->id,
                    'menu_id' => $request->menu_id,
                    'quantity' => $request->quantity,
                    'price' => $menu->price
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Item added to cart successfully',
                'data' => $cart->load('items.menu')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update cart item quantity
     */
    public function updateItem(Request $request, $cartItemId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'quantity' => 'required|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $cartItem = Carts_Items::find($cartItemId);

            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }

            $cartItem->quantity = $request->quantity;
            $cartItem->save();

            return response()->json([
                'success' => true,
                'message' => 'Cart item updated successfully',
                'data' => $cartItem
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove item from cart
     */
    public function removeItem($cartItemId)
    {
        try {
            $cartItem = Carts_Items::find($cartItemId);

            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }

            $cartItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove item from cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear cart
     */
    public function clearCart()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            Cart::where('user_id', $user->id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}