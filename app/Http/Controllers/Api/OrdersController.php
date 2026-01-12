<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\CartItem; 
use App\Models\Orders;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrdersController extends Controller
{
    /**
     * Get user orders
     */
    public function index()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $orders = Orders::with('items.menu', 'restaurant')
                ->where('user_id', $user->id)
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            Log::error('Get orders failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order detail
     */
    public function show($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $order = Orders::with('items.menu', 'restaurant')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order detail retrieved',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Get order detail failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create order from cart (CHECKOUT)
     * Combine all items from multiple restaurants into 1 order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        // Get ALL carts for user (bisa multiple restaurants)
        $carts = Cart::with(['items' => function($query) {
            $query->with('menu');
        }])
        ->where('user_id', $user->id)
        ->get();

        // Filter only carts yang punya items
        $cartsWithItems = $carts->filter(function($cart) {
            return $cart->items->count() > 0;
        });

        if ($cartsWithItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Generate unique order code
            $randomSuffix = strtoupper(substr(str_shuffle('0123456789ABCDEF'), 0, 4));
            $orderCode = 'ORD-' . now()->format('YmdHis') . $randomSuffix;

            // Collect ALL items dari semua restaurant
            $allItems = [];
            $totalPrice = 0;

            foreach ($cartsWithItems as $cart) {
                foreach ($cart->items as $item) {
                    $itemPrice = (float) $item->price;
                    $itemQuantity = (int) $item->quantity;
                    
                    $allItems[] = [
                        'menu_id'  => $item->menu_id,
                        'quantity' => $itemQuantity,
                        'price'    => $itemPrice,
                        'notes'    => $item->notes ?? '',
                    ];
                    
                    $totalPrice += $itemPrice * $itemQuantity;
                }
            }

            // Create 1 order dengan restaurant_id dari cart pertama
            $order = Orders::create([
                'order_code'     => $orderCode,
                'user_id'        => $user->id,
                'restaurant_id'  => $cartsWithItems->first()->restaurant_id,
                'total_price'    => $totalPrice,
                'status'         => 'pending',
                'notes'          => $validated['notes'] ?? null
            ]);

            // Move ALL items dari semua restaurants ke order ini
            $orderItems = [];
            foreach ($allItems as $itemData) {
                $orderItems[] = [
                    'order_id' => $order->id,
                    'menu_id'  => $itemData['menu_id'],
                    'quantity' => $itemData['quantity'],
                    'price'    => $itemData['price'],
                    'notes'    => $itemData['notes'],
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            if (!empty($orderItems)) {
                OrderItem::insert($orderItems);
            }

            // Clear ALL carts untuk user ini
            CartItem::whereIn('cart_id', $cartsWithItems->pluck('id'))->delete();
            Cart::whereIn('id', $cartsWithItems->pluck('id'))->delete();

            DB::commit();

            // Load fresh data untuk response
            $order = Orders::with('items.menu', 'restaurant')->find($order->id);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Order creation failed:', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order notes (hanya untuk pending)
     */
    public function updateNotes(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            $user = JWTAuth::parseToken()->authenticate();

            $order = Orders::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending orders can be edited'
                ], 400);
            }

            $order->update(['notes' => $validated['notes'] ?? null]);

            return response()->json([
                'success' => true,
                'message' => 'Order notes updated',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Update order notes failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order notes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order item notes (hanya untuk pending order)
     */
    public function updateItemNotes(Request $request, $id, $itemId)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            $user = JWTAuth::parseToken()->authenticate();

            $order = Orders::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending orders can be edited'
                ], 400);
            }

            $item = OrderItem::where('id', $itemId)
                ->where('order_id', $order->id)
                ->first();

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found'
                ], 404);
            }

            $item->update(['notes' => $validated['notes'] ?? null]);

            return response()->json([
                'success' => true,
                'message' => 'Item notes updated',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            Log::error('Update item notes failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update item notes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel order
     */
    public function cancel($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $order = Orders::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending orders can be canceled'
                ], 400);
            }

            DB::beginTransaction();

            // Delete order items
            OrderItem::where('order_id', $order->id)->delete();

            // Delete order
            $order->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order canceled successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Cancel order failed:', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin get all orders
     */
    public function getAllOrders()
    {
        try {
            $orders = Orders::with('user', 'restaurant', 'items.menu')
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'All orders retrieved',
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            Log::error('Get all orders failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}