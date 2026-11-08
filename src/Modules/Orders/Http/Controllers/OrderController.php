<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\ApiError;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\InventoryGateway;
use Modules\Inventory\Exceptions\InventoryUnavailableException;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Events\OrderStatusChanged;
use Modules\Orders\Http\Requests\StoreOrderRequest;
use Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use Modules\Orders\Http\Resources\OrderResource;
use Modules\Orders\Models\Order;
use Modules\Products\Contracts\ProductCatalog;
use Modules\Products\Contracts\ProductSummary;
use RuntimeException;
use Throwable;

/**
 * Orders talks to the other modules only through their public contracts:
 *  - Products  -> ProductCatalog (name + price of the ordered products)
 *  - Inventory -> InventoryGateway (reserve / release stock over gRPC)
 *  - Users     -> the 'admin' permission (Gate)
 *  - Notifications is never called: Orders publishes events (OrderPlaced, OrderStatusChanged).
 */
class OrderController extends Controller
{
    public function __construct(
        private InventoryGateway $inventory,
        private ProductCatalog $catalog,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $orders = Order::with('items')
            ->when(Gate::denies('admin'), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $user = $request->user();

        abort_if(
            Gate::denies('admin') && $order->user_id !== $user->id,
            403,
            'You are not allowed to access this order.'
        );

        return new OrderResource($order->load('items'));
    }

    /**
     * Create an order:
     *  1) ONE gRPC call reserves the quantities of all items in the inventory service.
     *  2) Rejected (not enough stock)  -> 409, the order is not created.
     *  3) Accepted                     -> save the order in our database.
     *  4) Saving fails after reserving -> give the reserved stock back (ReleaseStock).
     *  Inventory down / too slow       -> 503 / 504 with a clear message (deadline on every call).
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $items = collect($request->validated('items'))->map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
        ]);
        // Name + price come from the Products module's public contract (no access to its table).
        $products = collect($this->catalog->findMany($items->pluck('product_id')->all()));
        $reservationId = (string) Str::uuid();

        // 1) Reserve everything in a single gRPC call.
        try {
            $reservation = $this->inventory->reserve($reservationId, $items->all());
        } catch (InventoryUnavailableException $e) {
            if ($e->isTimeout()) {
                // The reservation may still be processed after we gave up: cancel it to be safe.
                // Done after the response is sent, so the user gets the 504 right at the deadline.
                app()->terminating(fn () => $this->releaseQuietly($reservationId));
            }
            throw $e; // rendered as 503 / 504 JSON
        }

        // 2) Not enough stock: clear message, nothing is created.
        if (! $reservation->success) {
            return ApiError::response(409, 'INSUFFICIENT_STOCK',
                $this->shortageMessage($reservation->shortages, $products, $reservation->message),
                ['shortages' => $reservation->shortages]);
        }

        // 3) Save the order.
        try {
            $order = DB::transaction(fn () => $this->saveOrder($request, $items, $products, $reservationId));
        } catch (Throwable $e) {
            report($e);

            // 4) Compensation: the order was not saved, so return the reserved quantities.
            $released = $this->releaseQuietly($reservationId);

            return ApiError::response(500, 'ORDER_NOT_SAVED', $released
                ? 'The order could not be saved. The reserved stock was returned to the inventory.'
                : 'The order could not be saved. Returning the reserved stock failed and was logged for manual release.');
        }

        // Tell whoever is interested (the Notifications module listens). Orders does not know who.
        event(new OrderPlaced($order->id, $order->user_id, (string) $request->user()->name));

        return (new OrderResource($order->load('items')))
            ->response()
            ->setStatusCode(201);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $order->update(['status' => $request->validated('status')]);

        event(new OrderStatusChanged($order->id, $order->user_id, $order->status));

        return new OrderResource($order->load('items'));
    }

    private function saveOrder(StoreOrderRequest $request, Collection $items, Collection $products, string $reservationId): Order
    {
        $order = Order::create([
            'user_id' => $request->user()->id,
            'status' => Order::STATUS_PENDING,
            'total' => 0,
            'reservation_id' => $reservationId,
        ]);

        $total = 0;

        foreach ($items as $item) {
            /** @var ProductSummary $product */
            $product = $products[$item['product_id']];

            // Snapshot of name and price at order time.
            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
            ]);

            $total += (float) $product->price * $item['quantity'];
        }

        $order->update(['total' => round($total, 2)]);

        // DEMO ONLY: proves the compensation path (step 4). Never active outside APP_ENV=local.
        if (config('inventory.simulate_order_save_failure') && app()->environment('local')) {
            throw new RuntimeException('Simulated database failure after the stock was reserved.');
        }

        return $order;
    }

    private function releaseQuietly(string $reservationId): bool
    {
        try {
            $this->inventory->release($reservationId);

            return true;
        } catch (InventoryUnavailableException $e) {
            Log::critical("Could not release stock reservation {$reservationId}: {$e->getMessage()}");

            return false;
        }
    }

    private function shortageMessage(array $shortages, Collection $products, string $fallback): string
    {
        if ($shortages === []) {
            return 'The order was rejected by the inventory: '.$fallback;
        }

        $parts = array_map(function (array $s) use ($products) {
            $name = $products[$s['product_id']]->name ?? "product #{$s['product_id']}";

            return "{$name} (requested {$s['requested']}, available {$s['available']})";
        }, $shortages);

        return 'Not enough stock for: '.implode(', ', $parts).'. The order was not created.';
    }
}
