<?php

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Exceptions\InventoryUnavailableException;
use Modules\Inventory\Services\ReservationResult;

/**
 * What the application needs from the inventory service.
 * The rest of the app depends on this interface, not on gRPC directly.
 */
interface InventoryGateway
{
    /**
     * @throws InventoryUnavailableException
     */
    public function getAvailableQuantity(int $productId): int;

    /**
     * Reserve all items of one order in a single call (all-or-nothing).
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     *
     * @throws InventoryUnavailableException
     */
    public function reserve(string $reservationId, array $items): ReservationResult;

    /**
     * Return the stock taken by a reservation. Safe to call more than once.
     *
     * @throws InventoryUnavailableException
     */
    public function release(string $reservationId): bool;
}
