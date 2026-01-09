<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Orders;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class OrdersController extends Controller
{
    /**
     * Get user orders
     */
    public function index()
    {
        $user = JWTAuth::parseToken()->authenticate();

        $orders = Orders::with('items.menu')
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully',
            'data' => $orders
        ]);
    }

    /**
     * Get order detail
     */
    public function show($id)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $order = Orders::with('items.menu')
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
        $carts = Cart::with('items.menu')
            ->where('user_id', $user->id)
            ->get();

        // Filter only carts yang punya items
        $cartsWithItems = $carts->filter(function($cart) {
            return !$cart->items->isEmpty();
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
                    $allItems[] = [
                        'cart_id' => $cart->id,
                        'item' => $item,
                        'restaurant_id' => $cart->restaurant_id
                    ];
                    $totalPrice += $item->price * $item->quantity;
                }
            }

            // Create 1 order dengan restaurant_id dari cart pertama
            // (atau bisa NULL jika ingin menandakan multi-restaurant order)
            $order = Orders::create([
                'order_code'     => $orderCode,
                'user_id'        => $user->id,
                'restaurant_id'  => $cartsWithItems->first()->restaurant_id, // Ambil restaurant pertama
                'total_price'    => $totalPrice,
                'status'         => 'pending',
                'notes'          => $validated['notes'] ?? null
            ]);

            // Move ALL items dari semua restaurants ke order ini
            foreach ($allItems as $data) {
                $item = $data['item'];
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_id'  => $item->menu_id,
                    'quantity' => $item->quantity,
                    'price'    => $item->price,
                    'notes'    => $item->notes
                ]);
            }

            // Clear ALL carts untuk user ini
            foreach ($cartsWithItems as $cart) {
                $cart->items()->delete();
                $cart->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->load('items.menu')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

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

        $order->update(['notes' => $validated['notes']]);

        return response()->json([
            'success' => true,
            'message' => 'Order notes updated',
            'data' => $order
        ]);
    }

    /**
     * Update order item notes (hanya untuk pending order)
     */
    public function updateItemNotes(Request $request, $id, $itemId)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

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

        $item->update(['notes' => $validated['notes']]);

        return response()->json([
            'success' => true,
            'message' => 'Item notes updated',
            'data' => $item
        ]);
    }

    /**
     * Cancel order
     */
    public function cancel($id)
    {
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

        try {
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
        $orders = Orders::with('user', 'restaurant', 'items.menu')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'All orders retrieved',
            'data' => $orders
        ]);
    }
}