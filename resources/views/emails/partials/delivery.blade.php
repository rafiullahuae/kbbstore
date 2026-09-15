{{--
    Where it is going, how, and how it was paid for.

    The payment line is $order['paymentLabel'], which is Order::paymentLabel() —
    the merchant's own wording snapshotted at checkout, falling back to the
    gateway's title. That method exists precisely so an order never prints a bare
    gateway id, and a customer is never shown the string "cod".
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:22px 0 0;">
    <tr>
        <td style="vertical-align:top;padding:0 12px 0 0;width:50%;">
            <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;margin-bottom:6px;">Delivery address</div>
            @forelse ($order['address'] as $line)
                <div style="color:#1d1d1f;">{{ $line }}</div>
            @empty
                <div style="color:#6b7280;">Not recorded</div>
            @endforelse
        </td>
        <td style="vertical-align:top;padding:0 0 0 12px;width:50%;">
            <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;margin-bottom:6px;">Delivery method</div>
            <div style="color:#1d1d1f;">{{ $order['deliveryMethod'] }}</div>

            <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;margin:14px 0 6px;">Payment method</div>
            <div style="color:#1d1d1f;">{{ $order['paymentLabel'] }}</div>
        </td>
    </tr>
</table>

@if ($order['giftNote'] !== '')
    <div style="margin:22px 0 0;padding:12px 14px;background:#faf6f2;border-radius:8px;">
        <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;margin-bottom:4px;">Gift message</div>
        <div style="color:#1d1d1f;white-space:pre-wrap;">{{ $order['giftNote'] }}</div>
    </div>
@endif

@if ($order['customerNote'] !== '')
    <div style="margin:14px 0 0;padding:12px 14px;background:#f5f5f7;border-radius:8px;">
        <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9096a1;margin-bottom:4px;">Order note</div>
        <div style="color:#1d1d1f;white-space:pre-wrap;">{{ $order['customerNote'] }}</div>
    </div>
@endif
