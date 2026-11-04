<?php

namespace Modules\Notifications\Listeners;

use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Events\OrderPlaced;

/** Reacts to the Orders module's public event; Orders never calls Notifications. */
class SendOrderPlacedNotifications
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(OrderPlaced $event): void
    {
        $this->notifications->notify($event->userId, 'order_created', "Your order #{$event->orderId} was placed.");
        $this->notifications->notifyAdmins('new_order', "New order #{$event->orderId} from {$event->customerName}.");
    }
}
