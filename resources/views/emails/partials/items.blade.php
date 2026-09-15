{{--
    The lines, as bought — with QUANTITY AS ITS OWN COLUMN.

    WHY THE COLUMN EXISTS. It used to read "2 × AED 199.00" in grey, 12.5px,
    under the product name, on the same line as the unit price. The people who
    pack these orders read this table, and a quantity buried in a price line is a
    quantity that gets read as a price and packed as one of something. The owner
    asked for it to be a column and it is one: its own cell, its own heading, its
    own weight, and a tinted chip so the eye lands on it before anything else.

    WHY THREE COLUMNS AND NOT FOUR. The obvious layout is Item / Qty / Unit /
    Total. At 320px — a phone held in one hand, which is where most of these are
    read — four columns leave the product name about sixty pixels, and a table
    cannot be made to reflow without a media query, which Gmail's web client
    strips out of a message body. So the unit price keeps its figure and its own
    labelled line inside the item cell ("AED 199.00 each"), where it belongs
    beside the thing it prices, and the two numbers a reader scans down — how
    many, and how much — are the columns. Nothing is lost: the unit price, the
    quantity and the line total are all still printed, and the plain-text twin
    prints all three too.

    Every value is the order_items snapshot handed over by OrderEmailPresenter —
    the name, brand, SKU and price as they were on the day. The live product is
    never consulted, so a renamed or deleted product does not rewrite history on
    a receipt that has already been sent once.
--}}
@php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin:16px 0 4px;">
    <tr>
        <td style="padding:0 0 7px;border-bottom:2px solid {{ $c['blush'] }};font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">Item</td>
        <td width="52" align="center" style="width:52px;padding:0 0 7px;border-bottom:2px solid {{ $c['blush'] }};font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;text-align:center;">Qty</td>
        <td width="92" align="right" style="width:92px;padding:0 0 7px;border-bottom:2px solid {{ $c['blush'] }};font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;text-align:right;">Total</td>
    </tr>

    @foreach ($order['items'] as $item)
        <tr>
            <td style="padding:12px 8px 12px 0;border-bottom:1px solid {{ $c['line'] }};vertical-align:top;font-size:14.5px;line-height:1.45;">
                <div style="font-weight:600;color:{{ $c['ink'] }};">{{ $item['name'] }}</div>

                @if ($item['brand'] !== '')
                    <div style="font-size:12.5px;color:{{ $c['pinkDeep'] }};">{{ $item['brand'] }}</div>
                @endif

                @if ($item['variant'] !== '')
                    <div style="font-size:12.5px;color:{{ $c['ink2'] }};">{{ $item['variant'] }}</div>
                @endif

                @if ($item['sku'] !== '')
                    <div style="font-size:11.5px;color:{{ $c['muted'] }};">SKU {{ $item['sku'] }}</div>
                @endif

                {{-- The unit price keeps its figure and gains a word. "AED
                     199.00 each" cannot be misread as a line total the way a
                     bare second number under a name can. --}}
                <div style="font-size:12.5px;color:{{ $c['ink2'] }};margin-top:4px;">
                    {!! $item['unitHtml'] !!} <span style="color:{{ $c['muted'] }};">each</span>
                </div>
            </td>

            {{-- The column the owner asked for. Centre-aligned in a tinted chip,
                 16px and bold: at a glance, from across a packing table. --}}
            <td width="52" align="center" style="width:52px;padding:12px 0;border-bottom:1px solid {{ $c['line'] }};vertical-align:top;text-align:center;">
                <div style="display:inline-block;min-width:26px;padding:3px 9px;background:{{ $c['pinkSoft'] }};border-radius:11px;font-size:16px;font-weight:700;color:{{ $c['pinkDeep'] }};line-height:1.35;">{{ $item['quantity'] }}</div>
            </td>

            <td width="92" align="right" style="width:92px;padding:12px 0 12px 8px;border-bottom:1px solid {{ $c['line'] }};text-align:right;vertical-align:top;white-space:nowrap;font-weight:700;font-size:14.5px;color:{{ $c['ink'] }};">
                {!! $item['lineHtml'] !!}
            </td>
        </tr>
    @endforeach
</table>
