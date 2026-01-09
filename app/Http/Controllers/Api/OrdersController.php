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
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        $cart = Cart::with('items.menu')
            ->where('user_id', $user->id)
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Generate order code
            $orderCode = 'ORD-' . now()->format('YmdHis');

            // Calculate total price
            $totalPrice = $cart->items->sum(function ($item) {
                return $item->price * $item->quantity;
            });

            // Create order WITH notes
            $order = Orders::create([
                'order_code'     => $orderCode,
                'user_id'        => $user->id,
                'restaurant_id'  => $cart->restaurant_id,
                'total_price'    => $totalPrice,
                'status'         => 'pending',
                'notes'          => $validated['notes'] ?? null
            ]);

            // Move cart items to order items
            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_id'  => $item->menu_id,
                    'quantity' => $item->quantity,
                    'price'    => $item->price,
                    'notes'    => $item->notes
                ]);
            }

            // Clear cart
            $cart->items()->delete();
            $cart->delete();

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