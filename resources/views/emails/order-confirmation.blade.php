{{--
    "Order confirmed 🎉" — the receipt, in look A (Lane EM, the owner's
    approved preview docs/rj-email-previews/after/01-order-confirmation.html).

    Sent when the order is paid (card, Tabby, Tamara) or placed with cash on
    delivery (Lane RL: never "we are packing it" before the money is in). The
    wording is RL's, word for word; the blocks are the kit's.

    The button is RL's signed "Track your order" link (App\Support\OrderLinks),
    which opens on any device.
--}}
@extends('emails.kit.order')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitFirst = \App\Services\Mail\Kit\KitOrder::firstName($order);
    $kitTitle = $kitTitle ?? __('email.kit.eyebrow_confirmed');
    $kitPreheader = __('email.kit.pre_confirmed', ['number' => $order['number']]);
    $kitHero = ['heart', 'pink', __('email.kit.eyebrow_confirmed'),
        $kitFirst !== '' ? __('email.confirmation.greeting_named', ['name' => $kitFirst]) : __('email.confirmation.greeting'),
        $paid ? __('email.confirmation.lead_paid') : __('email.confirmation.lead')];
    $kitTracker = [1, null];
    $kitPaid = $paid;
    $kitCta = [__('email.confirmation.track_button'), $trackSignedUrl, __('email.order_status.track_note')];
    $kitWhy = __('email.kit.why_order', ['site' => $k['site']]);
@endphp
