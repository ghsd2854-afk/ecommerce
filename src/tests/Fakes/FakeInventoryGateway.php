<?php

namespace Tests\Fakes;

use Modules\Inventory\Contracts\InventoryGateway;
use Modules\Inventory\Exceptions\InventoryUnavailableException;
use Modules\Inventory\Services\ReservationResult;

/**
 * In-memory replacement for the gRPC inventory service, used by the feature tests.
 * It behaves like the real service (all-or-nothing reservation, release) and can
 * simulate the service being down or too slow.
 */
class FakeInventoryGateway implements InventoryGateway
{
    /** @var array<int, int> product id => available quantity */
    public array $stock = [];

    /** @var array<string, array<int, int>> reservation id => [product id => quantity] */
    public array $reservations = [];

    /** @var array<int, string> */
    public array $released = [];

    /** null | 'down' (gRPC UNAVAILABLE) | 'slow' (gRPC DEADLINE_EXCEEDED) | 'crash' (unexpected error) */
    public ?string $failure = null;

    public function getAvailableQuantity(int $productId): int
    {
        $this->failIfNeeded('GetStock');

        return $this->stock[$productId] ?? 0;
    }

    public function reserve(string $reservationId, array $items): ReservationResult
    {
        $this->failIfNeeded('ReserveStock');

        $shortages = [];
        foreach ($items as $item) {
            $available = $this->stock[$item['product_id']] ?? 0;
            if ($available < $item['quantity']) {
                $shortages[] = ['product_id' => $item['product_id'], 'requested' => $item['quantity'], 'available' => $available];
            }
        }

        if ($shortages !== []) {
            return new ReservationResult(false, 'Insufficient stock.', $shortages);
        }

        foreach ($items as $item) {
            $this->stock[$item['product_id']] -= $item['quantity'];
            $this->reservations[$reservationId][$item['product_id']] = $item['quantity'];
        }

        return new ReservationResult(true, 'Stock reserved.');
    }

    public function release(string $reservationId): bool
    {
        $this->released[] = $reservationId;

        foreach ($this->reservations[$reservationId] ?? [] as $productId => $quantity) {
            $this->stock[$productId] += $quantity;
        }
        unset($this->reservations[$reservationId]);

        return true;
    }

    private function failIfNeeded(string $operation): void
    {
        if ($this->failure === 'down') {
            throw new InventoryUnavailableException($operation, 14, 'failed to connect to all addresses', 2000);
        }
        if ($this->failure === 'slow') {
            throw new InventoryUnavailableException($operation, 4, 'Deadline Exceeded', 2000);
        }
        if ($this->failure === 'crash') {
            throw new \RuntimeException('Unexpected internal failure (simulated).');
        }
    }
}
