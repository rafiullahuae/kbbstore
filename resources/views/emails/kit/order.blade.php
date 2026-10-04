{{--
    Every order email in look A — tools/rj-build-after.cjs orderEmail(), ported
    (Lane EM). The confirmation, both "Complete your order" reminders, every
    status email and the refund all extend this view, so they share ONE
    header, hero, tracker, order chip, item list, totals, help box, sign-off
    and footer, in the approved order:

        topbar · card [ header · hero · tracker? · order chip · @kit_before ·
        "Your items" + lines? · totals? · @kit_mid · infoPair? · button? +
        note? · @kit_after · help · sign-off ] · footer

    WHAT A CHILD VIEW SETS (all plain text unless said otherwise):
      $k            MailKit::for($brand)
      $kitTitle, $kitPreheader
      $kitHero      [icon, tone, eyebrow, title, lead]
      $kitTracker   null, or [at, stopped|null]
      $kitShowItems, $kitShowTotals, $kitShowInfo   bool (default true)
      $kitDue       bool: the unpaid "Total to pay / Not paid yet" grand row
      $kitPaid      bool: "Paid with …" under the total
      $kitCta       null, or [label, url, note|null] — the url built by the
                    Mailable (a signed OrderLinks URL or a site path)
      $kitChipExtra null, or an HtmlString built from escaped parts
      $kitWhy       the footer's "why you got this"
      $kitTemplate  the email's key in KitSections::TEMPLATES (Lane EK): its
                    stored section order and switches. Every block below is a
                    @kitsec; a switch the owner set wins over the $kitShow*
                    default through KitSections::on()
      $kitPromises  bool (default false): the three promises under the total
    and may fill @section('kit_before'), ('kit_mid'), ('kit_after').
--}}
@extends('emails.kit.doc')
@php
    // The one catalogue statement: pictures and routine steps, by id.
    $kitProducts = $kitProducts ?? \App\Services\Mail\Kit\KitOrder::products($order);
@endphp

@section('kit')
@include('emails.kit.topbar')
@include('emails.kit.card-open')
@include('emails.kit.header')
@kitsec('hero')
@include('emails.kit.hero', ['icon' => $kitHero[0], 'tone' => $kitHero[1], 'eyebrow' => $kitHero[2], 'title' => $kitHero[3], 'lead' => $kitHero[4]])
@endkitsec
@kitsec('tracker')
@if (($kitTracker ?? null) !== null && \App\Services\Mail\Kit\KitSections::on($kitTemplate ?? null, 'tracker', true))
@include('emails.kit.tracker', ['at' => $kitTracker[0], 'stopped' => $kitTracker[1] ?? null, 'labels' => \App\Services\Mail\Kit\KitOrder::steps()])
@endif
@endkitsec
@kitsec('chip')
@include('emails.kit.order-chip', ['chipNumber' => $order['number'], 'chipPlaced' => $order['placedShort'] ?? $order['placedAt'], 'chipTotal' => $order['totalPlain'], 'chipExtra' => $kitChipExtra ?? null])
@endkitsec
@kitsec('before')
@yield('kit_before')
@endkitsec
@kitsec('items')
@if (\App\Services\Mail\Kit\KitSections::on($kitTemplate ?? null, 'items', $kitShowItems ?? true))
@include('emails.kit.section-title', ['text' => __('email.kit.your_items')])
@include('emails.kit.items', ['lines' => \App\Services\Mail\Kit\KitOrder::lines($order, $kitProducts), 'showPrice' => true])
@endif
@endkitsec
@kitsec('totals')
@if (\App\Services\Mail\Kit\KitSections::on($kitTemplate ?? null, 'totals', $kitShowTotals ?? true))
@include('emails.kit.totals', ['rows' => \App\Services\Mail\Kit\KitOrder::rows($order), 'grand' => \App\Services\Mail\Kit\KitOrder::grand($order, $kitDue ?? false, $kitPaid ?? false)])
@endif
@endkitsec
@kitsec('promises')
@if (\App\Services\Mail\Kit\KitSections::on($kitTemplate ?? null, 'promises', $kitPromises ?? false))
@include('emails.kit.promises', ['promises' => [
    ['truck', __('email.reminder.why_fast'), __('email.reminder.why_fast_note')],
    ['tick', __('email.reminder.why_original'), __('email.reminder.why_original_note')],
    ['gift', __('email.reminder.why_samples'), __('email.reminder.why_samples_note')],
]])
@endif
@yield('kit_mid')
@endkitsec
@kitsec('info')
@if (\App\Services\Mail\Kit\KitSections::on($kitTemplate ?? null, 'info', $kitShowInfo ?? true))
@php $kitInfo = \App\Services\Mail\Kit\KitOrder::info($order); @endphp
@include('emails.kit.info-pair', ['left' => $kitInfo['left'], 'right' => $kitInfo['right']])
@include('emails.kit.order-notes')
@endif
@endkitsec
@kitsec('button')
@if (($kitCta ?? null) !== null)
@include('emails.kit.button', ['label' => $kitCta[0], 'href' => $kitCta[1]])
@if (($kitCta[2] ?? '') !== '')
@include('emails.kit.para', ['html' => $kitCta[2], 'pad' => '12px 32px 0', 'size' => 12.5, 'center' => true])
@endif
@endif
@endkitsec
@kitsec('after')
@yield('kit_after')
@endkitsec
@kitsec('help')
@include('emails.kit.help')
@endkitsec
@kitsec('signoff')
@include('emails.kit.signoff')
@endkitsec
@include('emails.kit.card-close')
@include('emails.kit.footer', ['why' => $kitWhy, 'unsubscribeUrl' => null])
@endsection
