{{--
    Where it is going, how, and how it was paid for.

    The payment line is $order['paymentLabel'], which is Order::paymentLabel() —
    the merchant's own wording snapshotted at checkout, falling back to the
    gateway's title. That method exists precisely so an order never prints a bare
    gateway id, and a customer is never shown the string "cod".

    TWO COLUMNS, AND WHY THEY STAY TWO. A two-column table at 50/50 is the widest
    thing here that a 320px client has to cope with, and it copes: each column
    gets about 118px, which holds a Dubai address line and a delivery method with
    wrapping. Three would not. There is no media query to fall back on — Gmail's
    web client strips <style> out of a message body — so this has to work at one
    width, and two columns of short lines is the shape that does.
--}}
@php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:22px 0 0;">
    <tr>
        <td style="vertical-align:top;padding:0 12px 0 0;width:50%;font-size:14px;line-height:1.5;">
            <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;margin-bottom:6px;">Delivery address</div>
            @forelse ($order['address'] as $line)
                <div style="color:{{ $c['ink'] }};">{{ $line }}</div>
            @empty
                <div style="color:{{ $c['ink2'] }};">Not recorded</div>
            @endforelse
        </td>
        <td style="vertical-align:top;padding:0 0 0 12px;width:50%;font-size:14px;line-height:1.5;">
            <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;margin-bottom:6px;">Delivery method</div>
            <div style="color:{{ $c['ink'] }};">{{ $order['deliveryMethod'] }}</div>

            <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;margin:14px 0 6px;">Payment method</div>
            <div style="color:{{ $c['ink'] }};">{{ $order['paymentLabel'] }}</div>
        </td>
    </tr>
</table>

@if ($order['giftNote'] !== '')
    <div style="margin:22px 0 0;padding:13px 15px;background:{{ $c['pinkSoft'] }};border-radius:9px;">
        <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['pinkDeep'] }};font-weight:700;margin-bottom:4px;">Gift message</div>
        <div style="color:{{ $c['ink'] }};white-space:pre-wrap;font-size:14px;line-height:1.5;">{{ $order['giftNote'] }}</div>
    </div>
@endif

@if ($order['customerNote'] !== '')
    <div style="margin:14px 0 0;padding:13px 15px;background:{{ $c['cream'] }};border-radius:9px;">
        <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;margin-bottom:4px;">Order note</div>
        <div style="color:{{ $c['ink'] }};white-space:pre-wrap;font-size:14px;line-height:1.5;">{{ $order['customerNote'] }}</div>
    </div>
@endif
