<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Http\Requests\StoreOrderRequest;
use Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use Modules\Orders\Http\Resources\OrderResource;
use Modules\Orders\Models\Order;
use Modules\Notifications\Services\NotificationService;
use Modules\Products\Models\Product;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $orders = Order::with('items.product')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $user = $request->user();

        abort_if(
            ! $user->isAdmin() && $order->user_id !== $user->id,
            403,
            'You are not allowed to access this order.'
        );

        return new OrderResource($order->load('items.product'));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $items = collect($request->validated('items'));
        $products = Product::whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');

        $order = DB::transaction(function () use ($request, $items, $products) {
            $order = Order::create([
                'user_id' => $request->user()->id,
                'status' => Order::STATUS_PENDING,
                'total' => 0,
            ]);

            $total = 0;

            foreach ($items as $item) {
                $product = $products[$item['product_id']];

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                ]);

                $total += $product->price * $item['quantity'];
            }

            $order->update(['total' => round($total, 2)]);

            return $order;
        });
        $notifier = app(NotificationService::class);
        $notifier->notify($request->user()->id, 'order_created', "Your order #{$order->id} was placed.");
        $notifier->notifyAdmins('new_order', "New order #{$order->id} from {$request->user()->name}.");
        return (new OrderResource($order->load('items.product')))
            ->response()
            ->setStatusCode(201);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $order->update(['status' => $request->validated('status')]);
        app(NotificationService::class)->notify($order->user_id, 'order_status', "Your order #{$order->id} is now {$order->status}.");
        return new OrderResource($order->load('items.product'));
    }
}
