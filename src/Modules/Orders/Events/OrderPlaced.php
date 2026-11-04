<?php

namespace Modules\Orders\Events;

/**
 * Published by the Orders module after an order is saved.
 * Orders does not know who listens (today: the Notifications module).
 */
final class OrderPlaced
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $customerName,
    ) {
    }
}
