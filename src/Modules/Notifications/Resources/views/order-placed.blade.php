<p>Hello {{ $customerName }},</p>
<p>Your order <strong>#{{ $orderId }}</strong> was received and is being processed.</p>
<p>You can follow its status here: <a href="{{ url('/orders/'.$orderId) }}">order #{{ $orderId }}</a>.</p>
<p>Thank you for shopping with us.</p>