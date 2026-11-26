<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Notifications\Mail\OrderPlacedMail;
use Modules\Orders\Events\OrderPlaced;
use Modules\Users\Contracts\UserDirectory;
use Throwable;

class SendOrderConfirmationEmail extends QueuedMailListener
{
    public function __construct(private UserDirectory $users)
    {
    }

    public function handle(OrderPlaced $event): void
    {
        $this->failIfSimulated();

        $email = $this->users->emailOf($event->userId);

        if ($email === null) {
            return; 
        }

        Mail::to($email)->send(new OrderPlacedMail($event->orderId, $event->customerName));
    }

  
    public function failed(OrderPlaced $event, Throwable $e): void
    {
        Log::critical("Order #{$event->orderId}: the confirmation e-mail could not be sent: {$e->getMessage()}");
    }
}