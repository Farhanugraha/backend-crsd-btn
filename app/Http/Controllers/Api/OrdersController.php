<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Carts_Items;
use App\Models\Order_Items;
use App\Models\Orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrdersController extends Controller
{
    /**
     * Get user orders
     */
    public function index()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $orders = Orders::where('user_id', $user->id)
                ->with('items.menu', 'restaurant', 'payment')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => $orders
            ], 200);
        } catch (\Exception $e) {
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
            $order = Orders::where('user_id', $user->id)
                ->with('items.menu', 'restaurant', 'payment')
                ->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order retrieved successfully',
                'data' => $order
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create order from cart
     */
    public function store(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            // Get cart
            $cart = Cart::where('user_id', $user->id)->first();

            if (!$cart || $cart->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty'
                ], 400);
            }

            // Calculate total price
            $totalPrice = 0;
            foreach ($cart->items as $item) {
                $totalPrice += $item->price * $item->quantity;
            }

            // Create order
            $order = Orders::create([
                'user_id' => $user->id,
                'restaurant_id' => $cart->restaurant_id,
                'total_price' => $totalPrice,
                'status' => 'pending'
            ]);

            // Transfer cart items to order items
            foreach ($cart->items as $cartItem) {
                Order_Items::create([
                    'order_id' => $order->id,
                    'menu_id' => $cartItem->menu_id,
                    'quantity' => $cartItem->quantity,
                    'price' => $cartItem->price
                ]);
            }

            // Clear cart
            CartItem::where('cart_id', $cart->id)->delete();
            $cart->delete();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->load('items.menu', 'restaurant')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel order (only pending orders)
     */
    public function cancel($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $order = Orders::where('user_id', $user->id)->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only cancel pending orders'
                ], 400);
            }

            $order->status = 'canceled';
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Order canceled successfully',
                'data' => $order
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all orders (admin only)
     */
    public function getAllOrders(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);
            $status = $request->get('status');

            $query = Orders::with('user', 'restaurant', 'items.menu', 'payment');

            if ($status) {
                $query->where('status', $status);
            }

            $orders = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'All orders retrieved successfully',
                'data' => $orders
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order status (admin only)
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:pending,paid,canceled'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = Orders::find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $order->status = $request->status;
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => $order
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
