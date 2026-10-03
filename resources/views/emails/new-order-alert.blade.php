{{--
    "New order" — to the shop, not a customer. Look A, Lane EM, from the
    owner's approved preview 10: no topbar, no header, no help box; the order
    in one line at the top, then what to pick, the money, where it goes and
    who bought it.

    ONE DELIBERATE DIFFERENCE FROM THE PREVIEW: its "Open in admin" button is
    not drawn. The admin address is a secret in this codebase (admin_path —
    App\Support\GuestRedirect, ExportProbe) and a button would put it in every
    alert's HTML, in every mail log it passes through. The sentence telling the
    owner where to find the order (email.alert.next_step) stands in its place.
--}}
@extends('emails.kit.doc')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.alert.heading');
    $kitPreheader = __('email.kit.pre_alert', ['number' => $order['number'], 'total' => $order['totalPlain']]);
    $kitCount = array_sum(array_map(static fn (array $i) => (int) ($i['quantity'] ?? 0), $order['items']));
    $kitWho = implode(' · ', array_values(array_filter([
        $order['customerName'],
        $order['paymentLabel'],
        trans_choice('email.kit.item_count', $kitCount, ['count' => $kitCount]),
    ], static fn ($v) => trim((string) $v) !== '')));
    $kitInfo = \App\Services\Mail\Kit\KitOrder::info($order);
    $kitCustomer = new \Illuminate\Support\HtmlString(implode('<br>', array_map('e', array_values(array_filter([$order['email'], $order['phone']], static fn ($v) => trim((string) $v) !== '')))));
@endphp

@section('kit')
@include('emails.kit.card-open')
<tr><td class="px" style="padding:26px 32px 0;font-family:{!! $k['sans'] !!};"><div style="font-family:{!! $k['sans'] !!};"><div style="font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:#2E9E6B;font-weight:800;">&#9679; {{ __('email.kit.eyebrow_alert') }}</div><div class="ink" style="margin-top:6px;font-size:26px;font-weight:800;color:{{ $k['text'] }};">{{ $order['number'] }} &middot; {{ $order['totalPlain'] }}</div><div class="ink2" style="margin-top:4px;font-size:14px;color:#5E545A;">{{ $kitWho }}</div></div></td></tr>
@include('emails.kit.para', ['html' => __('email.alert.next_step', ['number' => $order['number']]), 'pad' => '18px 32px 0', 'size' => 14])
@include('emails.kit.section-title', ['text' => __('email.alert.items_heading')])
@include('emails.kit.items', ['lines' => \App\Services\Mail\Kit\KitOrder::lines($order), 'showPrice' => true])
@include('emails.kit.totals', ['rows' => \App\Services\Mail\Kit\KitOrder::rows($order), 'grand' => [__('email.totals.total'), $order['totalPlain'], '']])
@include('emails.kit.info-pair', ['left' => [__('email.kit.ship_to'), $kitInfo['left'][1]], 'right' => [__('email.kit.customer'), $kitCustomer, __('email.totals.delivery'), $order['deliveryMethod']]])
@include('emails.kit.order-notes')
@include('emails.kit.gap', ['h' => 28])
@include('emails.kit.card-close')
@include('emails.kit.footer', ['why' => __('email.kit.why_alert'), 'unsubscribeUrl' => null])
@endsection
