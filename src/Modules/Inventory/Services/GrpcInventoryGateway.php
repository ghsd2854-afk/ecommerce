<?php

namespace Modules\Inventory\Services;

use Google\Protobuf\Internal\Message;
use Modules\Inventory\Contracts\InventoryGateway;
use Modules\Inventory\Exceptions\InventoryUnavailableException;
use Modules\Inventory\Grpc\GetStockRequest;
use Modules\Inventory\Grpc\InventoryServiceClient;
use Modules\Inventory\Grpc\ReleaseStockRequest;
use Modules\Inventory\Grpc\ReserveStockRequest;
use Modules\Inventory\Grpc\StockItem;
use Modules\Inventory\Grpc\StockShortage;

/**
 * Talks to the inventory service directly over gRPC.
 * Every call has a deadline (timeout): the user never waits forever.
 */
class GrpcInventoryGateway implements InventoryGateway
{
    private const GRPC_OK = 0;

    public function __construct(
        private readonly InventoryServiceClient $client,
        private readonly int $timeoutMs,
    ) {
    }

    public function getAvailableQuantity(int $productId): int
    {
        $request = new GetStockRequest(['product_id' => $productId]);

        /** @var \Modules\Inventory\Grpc\GetStockResponse $response */
        $response = $this->call('GetStock', $request);

        return (int) $response->getAvailableQuantity();
    }

    public function reserve(string $reservationId, array $items): ReservationResult
    {
        $request = new ReserveStockRequest([
            'reservation_id' => $reservationId,
            'items' => array_map(fn (array $item) => new StockItem([
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ]), array_values($items)),
        ]);

        /** @var \Modules\Inventory\Grpc\ReserveStockResponse $response */
        $response = $this->call('ReserveStock', $request);

        $shortages = [];
        /** @var StockShortage $shortage */
        foreach ($response->getShortages() as $shortage) {
            $shortages[] = [
                'product_id' => (int) $shortage->getProductId(),
                'requested' => (int) $shortage->getRequested(),
                'available' => (int) $shortage->getAvailable(),
            ];
        }

        return new ReservationResult($response->getSuccess(), $response->getMessage(), $shortages);
    }

    public function release(string $reservationId): bool
    {
        /** @var \Modules\Inventory\Grpc\ReleaseStockResponse $response */
        $response = $this->call('ReleaseStock', new ReleaseStockRequest(['reservation_id' => $reservationId]));

        return $response->getReleased();
    }

    /**
     * Unary gRPC call with a deadline. 'timeout' is in microseconds.
     *
     * @throws InventoryUnavailableException when the service is down, unreachable or too slow
     */
    private function call(string $method, Message $request): Message
    {
        [$response, $status] = $this->client
            ->{$method}($request, [], ['timeout' => $this->timeoutMs * 1000])
            ->wait();

        if ($status->code !== self::GRPC_OK || $response === null) {
            throw new InventoryUnavailableException($method, (int) $status->code, (string) $status->details, $this->timeoutMs);
        }

        return $response;
    }
}
