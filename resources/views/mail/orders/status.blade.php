<x-mail::message>
# {{ $heading }}

{{ $intro }}

**Order:** {{ $order->number }}<br>
**Placed:** {{ $order->created_at?->timezone(config('app.timezone'))->format('j M Y, g:i A') }}<br>
**Payment:** {{ $order->payment_method === 'cod' ? 'Cash on delivery' : 'Online' }}{{ $order->payment_status === 'paid' ? ' (paid)' : '' }}

<x-mail::table>
| Item | Qty | Amount |
|:-----|:---:|-------:|
@foreach ($items as $item)
| {{ $md($item->title) }}@if ($item->part_no) ({{ $md($item->part_no) }})@endif | {{ $item->qty }}@if ((int) $item->qty_cancelled > 0) ({{ $item->qty_cancelled }} cancelled)@endif | ₹{{ number_format((float) $item->line_total, 2) }} |
@endforeach
| Subtotal | | ₹{{ number_format((float) $order->subtotal, 2) }} |
| Shipping | | {{ (float) $order->shipping_fee > 0 ? '₹'.number_format((float) $order->shipping_fee, 2) : 'Free' }} |
| **Total** | | **₹{{ number_format((float) $order->grand_total, 2) }}** |
</x-mail::table>

**Shipping to**<br>
{{ $md($order->shipping_name) }}<br>
{{ $md($order->shipping_line1) }}@if ($order->shipping_line2), {{ $md($order->shipping_line2) }}@endif<br>
{{ $md($order->shipping_city) }}, {{ $md($order->shipping_state) }} {{ $md($order->shipping_pincode) }}<br>
@if ($forStaff)
Phone: {{ $md($order->shipping_phone) }}<br>
Customer: {{ $md($order->user?->email ?: $order->user?->phone) }}
@endif

@if ($orderUrl)
<x-mail::button :url="$orderUrl">
View order
</x-mail::button>
@endif

Thanks,<br>
{{ config('mail.from.name') }}
</x-mail::message>
