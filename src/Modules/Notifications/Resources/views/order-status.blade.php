<p>Hello,</p>
<p>The status of your order <strong>#{{ $orderId }}</strong> is now <strong>{{ $status }}</strong>.</p>
<p>Details: <a href="{{ url('/orders/'.$orderId) }}">order #{{ $orderId }}</a>.</p>