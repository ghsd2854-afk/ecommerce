<?php

namespace Modules\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Modules\Orders\Models\Order;


final class OrderPlaced implements ShouldBroadcast
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $customerName,
    ) {
    }

  
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

   
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer' => $this->customerName,
            'status' => Order::STATUS_PENDING,
        ];
    }
}