<?php

namespace Modules\Orders\Events;

/** Published by the Orders module when an admin changes an order's status. */
final class OrderStatusChanged
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $status,
    ) {
    }
}
