{{--
    Every order-status email in look A (Lane EM): processing, on hold, shipped
    🚚💨, delivered ✨, cancelled, refunded and payment failed 😔 — the owner's
    approved previews 04, 05, 06, 07 and 09 in docs/rj-email-previews/after/.

    Lane RL decided WHAT each says and WHEN it goes (OrderStatusChanged,
    OrderStatusMailPolicy); its wording is used word for word. This file only
    decides which of the kit's blocks each status shows, from the preview:

      status       tracker              before        items totals info  button
      processing   Confirmed            —             yes   yes    yes   Track
      onhold       Confirmed            "What we need" yes  no     yes   WhatsApp
      shipped      Shipped              tracking note yes   no     yes   Track
      completed    Delivered            tracking note yes   no     no    —
      cancelled    ✕ Cancelled          refund note   yes   yes    no    Shop again
      refunded     —                    —             yes   yes    no    View order
      failed       ✕ Not paid           —             yes   due    no    Complete

    $note (on hold) is the owner's own sentence, escaped, line breaks kept.
--}}
@extends('emails.kit.order')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitPreheader = __('email.kit.pre_status', ['number' => $order['number']]);
    $kitWhy = __('email.kit.why_order', ['site' => $k['site']]);
    $kitTrack = [__('email.order_status.track_button'), $ctaUrl, __('email.order_status.track_note')];

    $kitShape = [
        'processing' => ['box', 'pink', __('email.kit.eyebrow_processing'), [1, null], true, true, $kitTrack],
        'onhold' => ['pause', 'amber', __('email.kit.eyebrow_onhold'), [1, null], false, true, null],
        'shipped' => ['truck', 'pink', __('email.kit.eyebrow_shipped'), [2, null], false, true, $kitTrack],
        'completed' => ['gift', 'green', __('email.kit.eyebrow_delivered'), [3, null], false, false, null],
        'cancelled' => ['cross', 'red', __('email.kit.eyebrow_cancelled'), [1, __('email.kit.step_cancelled')], true, false, [__('email.kit.shop_again'), \App\Support\Url::external('/shop/'), null]],
        'refunded' => ['back', 'green', __('email.kit.eyebrow_refunded'), null, true, false, [$ctaLabel, $ctaUrl, null]],
        'failed' => ['card', 'red', __('email.kit.eyebrow_failed'), [0, __('email.kit.step_not_paid')], true, false, [$ctaLabel, $ctaUrl, __('email.reminder.button_note')]],
    ][$status] ?? ['heart', 'pink', __('email.kit.eyebrow_processing'), null, true, true, [$ctaLabel, $ctaUrl, null]];

    /*
     * On hold: "Reply on WhatsApp" to the shop's own WhatsApp when one is set
     * (EmailBranding::support(), already a wa.me link the kit re-checked);
     * the order's own page otherwise -- never a button to nowhere.
     */
    if ($status === 'onhold') {
        $kitWa = collect($k['support'])->firstWhere('kind', 'whatsapp');
        $kitShape[6] = $kitWa !== null
            ? [__('email.kit.reply_whatsapp'), $kitWa['url'], null]
            : [$ctaLabel, $ctaUrl, null];
    }

    $kitTitle = $kitTitle ?? $heading;
    $kitHero = [$kitShape[0], $kitShape[1], $kitShape[2], $heading,
        $status === 'cancelled' ? __('email.order_status.cancelled_body') : $body];
    $kitTracker = $kitShape[3];
    $kitShowTotals = $kitShape[4];
    $kitShowInfo = $kitShape[5];
    $kitCta = $kitShape[6];
    $kitDue = $status === 'failed';
    $kitPaid = false;
    // Read once here (the kit layout reuses it): pictures and routine steps.
    $kitProducts = \App\Services\Mail\Kit\KitOrder::products($order);

    // The box under the order chip: what the owner needs (on hold), that the
    // order number is the tracking number (shipped, delivered), or what
    // happens to the money (cancelled). Escaped parts, the only markup <b>,
    // <br> and the kit's own <span>.
    $kitNotice = match (true) {
        $status === 'onhold' => ['amber', new \Illuminate\Support\HtmlString(($note !== '' ? '<b>' . e(__('email.order_status.onhold_need')) . '</b> ' . nl2br(e($note)) . ' ' : '') . e(__('email.order_status.onhold_reply')))],
        $trackable && in_array($status, ['shipped', 'completed'], true) => ['pink', new \Illuminate\Support\HtmlString('<b>' . e(__('email.order_status.tracking_number', ['number' => $order['number']])) . '</b><br><span style="font-size:13px;color:#5E545A;">' . e(__('email.order_status.tracking_where')) . '</span>')],
        $status === 'cancelled' && $refundSentence !== '' => ['red', $refundSentence],
        default => null,
    };
@endphp

@section('kit_after')
@if ($status === 'completed' && ($kitHowTo = \App\Services\Mail\Kit\KitOrder::howTo($order, $kitProducts)) !== null)
@include('emails.kit.para', ['html' => $kitHowTo, 'pad' => '22px 32px 0', 'size' => 14])
@endif
@endsection

@section('kit_before')
@if ($kitNotice !== null)
@include('emails.kit.notice', ['tone' => $kitNotice[0], 'html' => $kitNotice[1]])
@endif
@endsection
