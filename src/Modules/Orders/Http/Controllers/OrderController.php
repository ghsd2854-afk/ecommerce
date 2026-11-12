<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Support\Performance;
use App\Support\ReadYourWrites;
use Illuminate\Database\Eloquent\Builder;
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
use Modules\Orders\Services\SalesReport;
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

    /**
     * Requirement 3:
     *  - N+1 fixed: the items of ALL orders on the page are loaded with ONE extra query
     *    (with('items') -> WHERE order_id IN (...)) instead of one query per order.
     *  - read replica: the list is read from the replica, except right after this user
     *    placed an order / an order status changed (read-your-writes -> primary).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Order::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $primary = $this->readsPrimary($request);

        $query = Order::query()
            ->when($primary, fn ($q) => $q->useWritePdo())
            ->when(Gate::denies('admin'), fn ($q) => $q->where('user_id', $user->id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id');

        // perf:mode baseline switches this off to MEASURE the N+1 problem (1 + N queries).
        if (Performance::on('eager_loading')) {
            $query->with(['items' => fn ($q) => $primary ? $q->useWritePdo() : $q]);
        }

        return OrderResource::collection($query->paginate(
            $filters['per_page'] ?? 10,
            total: Gate::allows('admin') ? $this->cachedAdminTotal($query, $filters['status'] ?? null) : null,
        ));
    }

    /**
     * The admin list counts ALL orders (SELECT COUNT(*) over hundreds of thousands of rows)
     * on every page: the slowest part of that request. The total is only used for the page
     * numbers, so it may be up to 30 s old: cached in Redis. (Customers have few orders:
     * their count stays exact.)
     */
    private function cachedAdminTotal(Builder $query, ?string $status): ?int
    {
        if (! Performance::on('cache')) {
            return null; // paginate() counts itself
        }

        try {
            return (int) Performance::store()->remember('orders:admin_total:'.($status ?? 'all'), 30, fn () => $query->toBase()->getCountForPagination());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $user = $request->user();

        abort_if(
            Gate::denies('admin') && $order->user_id !== $user->id,
            403,
            'You are not allowed to access this order.'
        );

        $primary = $this->readsPrimary($request);

        return new OrderResource($order->load(['items' => fn ($q) => $primary ? $q->useWritePdo() : $q]));
    }

    /**
     * Admin sales report (daily orders + revenue of the last N days).
     * Slow on hundreds of thousands of orders without the (created_at, status, total)
     * index; cached in Redis for a short TTL (a report may be a minute old).
     */
    public function stats(Request $request, SalesReport $report): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        return response()->json(['data' => $report->lastDays((int) ($validated['days'] ?? 30))]);
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

        // Read-your-writes: this user's next reads (the new order, its notification) go to the
        // primary until the replica has them. Called AFTER the event, so the GTID also covers
        // the notifications written by the listeners.
        ReadYourWrites::remember('user:'.$order->user_id);

        return (new OrderResource($order->load('items')))
            ->response()
            ->setStatusCode(201);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $order->update(['status' => $request->validated('status')]);

        event(new OrderStatusChanged($order->id, $order->user_id, $order->status));

        // Both the admin and the order's owner (new status + its notification) see it immediately.
        ReadYourWrites::remember('user:'.$request->user()->id);
        ReadYourWrites::remember('user:'.$order->user_id);

        return new OrderResource($order->load('items'));
    }

    private function readsPrimary(Request $request): bool
    {
        return ReadYourWrites::mustReadPrimary('user:'.$request->user()->id);
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
