@extends('emails.layout')

{{--
    "We have your order" — the receipt, and the one email this store could least
    afford to get wrong.

    THE OWNER ASKED FOR IT TO BE WARMER: "a little bit colourful, joyful/happy
    about skincare". So the greeting leads with the feeling and the order number
    follows it, rather than the other way round; the order chip is in the brand
    blush rather than grey; the button is the brand pink. What has NOT changed is
    the order of the information — what you bought, what it cost, where it is
    going — because this is still the document a customer opens six weeks later
    to check what they were charged, and a receipt that is fun to read and hard
    to check is a worse receipt.

    Restraint is deliberate. Two pinks, a cream and the site's ink, all from
    EmailBranding::PALETTE, which took them from the storefront's own :root block.
    One piece of punctuation carries the joy. That is the whole budget.
--}}
@section('body')
    @php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp

    <p style="margin:0 0 6px;font-size:20px;font-weight:700;color:{{ $c['ink'] }};line-height:1.3;">
        Thank you{{ $order['customerName'] !== '' ? ', ' . $order['customerName'] : '' }} ✨
    </p>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:{{ $c['ink2'] }};">
        Your order is in and we are packing it with care. Everything you chose is listed below,
        exactly as it was when you ordered — keep this email, it is your receipt.
    </p>

    {{-- The order chip. Same information as before, in the brand's own tint
         rather than the flat grey, and the number kept at the size a customer
         can read back to us over WhatsApp. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['cream'] }}" style="width:100%;border-collapse:collapse;background:{{ $c['cream'] }};border-radius:9px;">
        <tr>
            <td style="padding:13px 15px;font-size:14px;line-height:1.5;color:{{ $c['ink'] }};">
                <span style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:{{ $c['muted'] }};font-weight:700;">Order</span>
                <span style="font-weight:700;margin-left:7px;font-size:16px;color:{{ $c['pinkDeep'] }};">{{ $order['number'] }}</span>
                @if ($order['placedAt'] !== '')
                    <span style="color:{{ $c['ink2'] }};margin-left:10px;">placed {{ $order['placedAt'] }}</span>
                @endif
            </td>
        </tr>
    </table>

    @include('emails.partials.items')
    @include('emails.partials.totals')
    @include('emails.partials.delivery')

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:26px 0 10px;">
        <tr>
            {{-- The button as a table cell with bgcolor, not a styled <a>.
                 Outlook's Word renderer drops padding and background from an
                 inline anchor and leaves a bare blue link; a cell with a bgcolor
                 attribute it does render. --}}
            <td bgcolor="{{ $c['pinkDeep'] }}" style="background:{{ $c['pinkDeep'] }};border-radius:7px;">
                <a href="{{ $order['trackUrl'] }}" style="display:inline-block;padding:13px 26px;color:{{ $c['white'] }};font-size:15px;font-weight:600;text-decoration:none;">Track your order</a>
            </td>
        </tr>
    </table>

    {{--
        The link above only opens on the browser that placed the order — the
        order-received page is gated to that session, and no link from this app
        carries its own authority (OrderEmailPresenter::trackUrl explains why).
        Said here rather than discovered on a phone.
    --}}
    <p style="margin:0;font-size:13px;line-height:1.55;color:{{ $c['ink2'] }};">
        That link opens on the device you ordered from. Anywhere else,
        <a href="{{ $order['accountUrl'] }}" style="color:{{ $c['pinkDeep'] }};font-weight:600;">sign in to your account</a>
        and your orders are all listed there under {{ $order['number'] }}.
    </p>
@endsection
