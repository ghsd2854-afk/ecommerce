<?php

namespace Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderStatusMail extends Mailable
{
    public function __construct(public int $orderId, public string $status)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Order #{$this->orderId} is now {$this->status}");
    }

    public function content(): Content
    {
        return new Content(view: 'notifications::order-status');
    }
}