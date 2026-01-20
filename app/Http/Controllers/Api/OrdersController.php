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
     * Load items dengan menu.restaurant.area untuk ekstrak area
     */
    public function index()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $orders = Orders::with(['items.menu.restaurant.area'])
                ->where('user_id', $user->id)
                ->latest()
                ->get();

            // Transform data untuk include unique areas
            $orders = $orders->map(function($order) {
                $order->areas = $this->extractAreasFromOrder($order);
                return $order;
            });

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
     * Get order detail (user - hanya order mereka)
     */
    public function show($id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $order = Orders::with(['items.menu.restaurant.area'])
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Add areas collection
            $order->areas = $this->extractAreasFromOrder($order);

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
     * Admin get single order detail (by order ID)
     * Load dengan items.menu.restaurant.area untuk ekstrak semua area
     */
    public function getOrderDetail($id)
    {
        try {
            $order = Orders::with([
                'user',
                'items.menu.restaurant.area'
            ])->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Add areas collection dari semua items
            $order->areas = $this->extractAreasFromOrder($order);

            return response()->json([
                'success' => true,
                'message' => 'Order detail retrieved',
                'data' => $order
            ], 200);

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
     * Helper: Extract unique areas dari order items
     * Return collection of unique areas
     */
    private function extractAreasFromOrder($order)
    {
        $areas = collect();
        
        if ($order->items) {
            foreach ($order->items as $item) {
                if ($item->menu && $item->menu->restaurant && $item->menu->restaurant->area) {
                    $area = $item->menu->restaurant->area;
                    
                    // Only add if not already in collection (unique by id)
                    if (!$areas->contains('id', $area->id)) {
                        $areas->push($area);
                    }
                }
            }
        }
        
        return $areas->values();
    }

    /**
     * Create order from cart (CHECKOUT)
     * SOLUSI ALTERNATIF: Gunakan restaurant_id dari item pertama
     * Alasan: Database constraint masih NOT NULL
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        $user = JWTAuth::parseToken()->authenticate();

        // Get ALL carts for user (bisa multiple restaurants)
        $carts = Cart::with(['items' => function($query) {
            $query->with('menu.restaurant');
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
            $firstRestaurantId = null;  

            foreach ($cartsWithItems as $cart) {
                foreach ($cart->items as $item) {
                    $itemPrice = (float) $item->price;
                    $itemQuantity = (int) $item->quantity;
               
                    if ($firstRestaurantId === null && $item->menu && $item->menu->restaurant_id) {
                        $firstRestaurantId = $item->menu->restaurant_id;
                    }
                    
                    $allItems[] = [
                        'menu_id'  => $item->menu_id,
                        'quantity' => $itemQuantity,
                        'price'    => $itemPrice,
                        'notes'    => $item->notes ?? '',
                    ];
                    
                    $totalPrice += $itemPrice * $itemQuantity;
                }
            }

     
            $order = Orders::create([
                'order_code'     => $orderCode,
                'user_id'        => $user->id,
                'restaurant_id'  => $firstRestaurantId ?? 0,  // Fallback ke 0 jika tidak ada
                'total_price'    => (int) $totalPrice,
                'status'         => 'pending',
                'order_status'   => null,
                'notes'          => $validated['notes'] ?? null
            ]);

            // Move ALL items dari semua restaurants ke order ini
            $orderItems = [];
            foreach ($allItems as $itemData) {
                $orderItems[] = [
                    'order_id'   => $order->id,
                    'menu_id'    => $itemData['menu_id'],
                    'quantity'   => $itemData['quantity'],
                    'price'      => (string) $itemData['price'],
                    'notes'      => $itemData['notes'],
                    'is_checked' => false,
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

            // Load fresh data dengan areas
            $order = Orders::with(['items.menu.restaurant.area', 'user'])->find($order->id);
            $order->areas = $this->extractAreasFromOrder($order);

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
     * Update payment status
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:paid,rejected'
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

            if ($order->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending orders can be updated'
                ], 400);
            }

            DB::beginTransaction();

            try {
                if ($validated['status'] === 'paid') {
                    $order->update([
                        'status' => 'paid',
                        'order_status' => 'processing'
                    ]);
                } else {
                    $order->update([
                        'status' => 'rejected',
                        'order_status' => null
                    ]);
                }

                DB::commit();
                $order->refresh();

                // Load dengan areas
                $order = Orders::with(['items.menu.restaurant.area', 'user'])->find($order->id);
                $order->areas = $this->extractAreasFromOrder($order);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment status updated successfully',
                    'data' => $order
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Update payment status failed:', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status',
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
     * Update order item notes
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
     * Update order status (untuk Admin)
     */
    public function updateOrderStatus(Request $request, $id) 
    {
        $validated = $request->validate([
            'order_status' => 'required|in:processing,completed,canceled'
        ]);

        try {
            $order = Orders::find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only update order status for paid orders'
                ], 400);
            }

            $currentStatus = $order->order_status;
            $newStatus = $validated['order_status'];

            $allowedTransitions = [
                null => ['processing', 'canceled'],
                'processing' => ['completed', 'canceled'],
                'completed' => [],
                'canceled' => []
            ];

            if (!isset($allowedTransitions[$currentStatus]) ||
                !in_array($newStatus, $allowedTransitions[$currentStatus])) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot transition from {$currentStatus} to {$newStatus}"
                ], 400);
            }

            $order->update(['order_status' => $newStatus]);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated',
                'data' => $order
            ]);

        } catch (\Exception $e) {
            Log::error('Update order status failed:', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
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
            
            try {
                OrderItem::where('order_id', $order->id)->delete();
                $order->delete();
                
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Order canceled and deleted successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Cancel order failed:', ['order_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending orders dengan areas
     */
    public function getPendingOrders()
    {
        try {
            $orders = Orders::with(['user', 'items.menu.restaurant.area'])
                ->where('order_status', 'processing')
                ->where('status', 'paid')  
                ->latest()
                ->get();

            // Add areas untuk setiap order
            $orders = $orders->map(function($order) {
                $order->areas = $this->extractAreasFromOrder($order);
                return $order;
            });

            return response()->json([
                'success' => true,
                'message' => 'Pending orders retrieved',
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            Log::error('Get pending orders failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batch update status
     */
    public function batchUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'required|integer|exists:orders,id',
            'order_status' => 'required|in:processing,completed,canceled'
        ]);

        try {
            $updatedCount = Orders::whereIn('id', $validated['order_ids'])
                ->where('status', 'paid')
                ->update(['order_status' => $validated['order_status']]);

            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} orders updated",
                'data' => ['updated_count' => $updatedCount]
            ]);
        } catch (\Exception $e) {
            Log::error('Batch update failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle item checked
     */
    public function toggleItemChecked(Request $request, $orderId, $itemId)
    {
        try {
            $order = Orders::find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'paid' || $order->order_status !== 'processing') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only check items for processing orders with paid status'
                ], 400);
            }

            $item = OrderItem::where('id', $itemId)
                ->where('order_id', $orderId)
                ->first();

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found'
                ], 404);
            }

            $item->is_checked = !$item->is_checked;
            $item->save();

            return response()->json([
                'success' => true,
                'message' => 'Item checked status updated',
                'data' => [
                    'item_id' => $item->id,
                    'is_checked' => $item->is_checked
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Toggle item checked failed:', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update item checked status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get checked items count
     */
    public function getCheckedItemsCount($orderId)
    {
        try {
            $order = Orders::find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $totalItems = $order->items()->count();
            $checkedItems = $order->items()->where('is_checked', true)->count();
            $allChecked = $totalItems > 0 && $checkedItems === $totalItems;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_items' => $totalItems,
                    'checked_items' => $checkedItems,
                    'all_checked' => $allChecked
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get checked items count failed:', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get checked items count',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin get all orders dengan areas
     * MAIN ENDPOINT untuk OrdersPage
     */
    public function getAllOrders()
    {
        try {
            $orders = Orders::with(['user', 'items.menu.restaurant.area'])
                ->latest()
                ->get();

            // Transform: add areas collection untuk setiap order
            $orders = $orders->map(function($order) {
                $order->areas = $this->extractAreasFromOrder($order);
                return $order;
            });

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

    /**
     * Admin get orders by status dengan areas
     */
    public function getOrdersByStatus($status)
    {
        try {
            $validStatuses = ['processing', 'completed', 'canceled'];
            
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status'
                ], 400);
            }

            $orders = Orders::with(['user', 'items.menu.restaurant.area'])
                ->where('order_status', $status)
                ->latest()
                ->get();

            // Add areas
            $orders = $orders->map(function($order) {
                $order->areas = $this->extractAreasFromOrder($order);
                return $order;
            });

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved',
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            Log::error('Get orders by status failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}