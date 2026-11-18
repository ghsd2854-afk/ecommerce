<?php

namespace Modules\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


final class OrderStatusChanged implements ShouldBroadcast
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $status,
    ) {
    }

   
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->orderId)];
    }

    public function broadcastAs(): string
    {
        return 'order.status';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
        ];
    }
}