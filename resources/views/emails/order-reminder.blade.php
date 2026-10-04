{{--
    "Complete your order 🛍️" (30 minutes) and "Your order is still waiting ⏳"
    (24 hours) in look A — Lane EM, from the owner's approved previews 02 and
    03. Lane RL's wording and its signed pay link (OrderLinks::payUrl), which
    opens THIS order on any device with every payment method the checkout
    offers.

    The three promises sit under the total as thin rows, icon left, as the
    owner asked: "these boxes i need thin. left icon and text. and place under
    the total bill row."
--}}
@extends('emails.kit.order')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitSecond = ($stage ?? 1) === 2;
    $kitTitle = $kitTitle ?? $heading;
    $kitPreheader = __($kitSecond ? 'email.kit.pre_reminder_second' : 'email.kit.pre_reminder_first', ['number' => $order['number']]);
    $kitHero = $kitSecond
        ? ['clock', 'red', __('email.kit.eyebrow_reminder_second'), $heading, $body]
        : ['bag', 'amber', __('email.kit.eyebrow_reminder_first'), $heading, $body];
    $kitTracker = [0, null];
    $kitDue = true;
    $kitShowInfo = false;
    $kitCta = [__('email.reminder.button'), $payUrl, __('email.reminder.button_note')];
    $kitWhy = __('email.kit.why_order', ['site' => $k['site']]);
    $kitTemplate = $kitSecond ? 'order_reminder_2' : 'order_reminder_1';
    $kitPromises = true;
@endphp


@section('kit_after')
@include('emails.kit.para', ['html' => $closing, 'pad' => '10px 32px 0', 'size' => 12.5, 'center' => true])
@endsection
