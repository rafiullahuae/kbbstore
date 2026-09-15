{{--
    The lines, as bought.

    Every value is the order_items snapshot handed over by OrderEmailPresenter —
    the name, brand, SKU and price as they were on the day. The live product is
    never consulted, so a renamed or deleted product does not rewrite history on
    a receipt that has already been sent once.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:18px 0 4px;">
    @foreach ($order['items'] as $item)
        <tr>
            <td style="padding:11px 0;border-bottom:1px solid #eeeef2;vertical-align:top;">
                <div style="font-weight:600;color:#1d1d1f;">{{ $item['name'] }}</div>

                @if ($item['brand'] !== '')
                    <div style="font-size:12.5px;color:#6b7280;">{{ $item['brand'] }}</div>
                @endif

                @if ($item['variant'] !== '')
                    <div style="font-size:12.5px;color:#6b7280;">{{ $item['variant'] }}</div>
                @endif

                @if ($item['sku'] !== '')
                    <div style="font-size:12px;color:#9096a1;">SKU {{ $item['sku'] }}</div>
                @endif

                <div style="font-size:12.5px;color:#6b7280;margin-top:3px;">
                    {{ $item['quantity'] }} &times; {!! $item['unitHtml'] !!}
                </div>
            </td>
            <td style="padding:11px 0;border-bottom:1px solid #eeeef2;text-align:right;vertical-align:top;white-space:nowrap;font-weight:600;">
                {!! $item['lineHtml'] !!}
            </td>
        </tr>
    @endforeach
</table>
