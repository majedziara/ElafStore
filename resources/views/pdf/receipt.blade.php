<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#302722}h1{font-size:24px}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left;border-bottom:1px solid #ddd}.amount{text-align:right}.muted{color:#71645b}
</style></head><body>
<h1>Nella &amp; Vale</h1><h2>Payment receipt</h2>
<p>{{ $order->reference }} · Paid {{ $order->paid_at->format('Y-m-d H:i') }} UTC</p>
<p>{{ $order->shipping_address['name'] }}<br>{{ $order->email }}</p>
<table><thead><tr><th>Item</th><th>Quantity</th><th class="amount">Amount (USD)</th></tr></thead><tbody>
@foreach($order->items as $item)
<tr><td>{{ $item->product_name }}<br><span class="muted">{{ $item->color }} / {{ $item->size }}</span></td><td>{{ $item->quantity }}</td><td class="amount">{{ \App\Support\Money::decimal($item->unit_price_cents*$item->quantity) }}</td></tr>
@endforeach
</tbody></table>
<p>Subtotal: USD {{ \App\Support\Money::decimal($order->subtotal_cents) }}</p>
<p>Discount: USD {{ \App\Support\Money::decimal($order->discount_cents) }}</p>
<p>Shipping: USD {{ \App\Support\Money::decimal($order->shipping_cents) }}</p>
<p>Tax: USD {{ \App\Support\Money::decimal($order->tax_cents) }}</p>
<h3>Total paid: USD {{ \App\Support\Money::decimal($order->total_cents) }}</h3>
@if($order->refunded_cents)<p>Refunded: USD {{ \App\Support\Money::decimal($order->refunded_cents) }}</p>@endif
<p class="muted">This receipt records the payment. Shipment and refund progress are shown in your order details.</p>
</body></html>
