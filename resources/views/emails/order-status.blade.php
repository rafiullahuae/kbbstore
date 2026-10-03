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
        'processing' => ['box', 'pink', 'eyebrow_processing', [1, null], true, true, $kitTrack],
        'onhold' => ['pause', 'amber', 'eyebrow_onhold', [1, null], false, true, null],
        'shipped' => ['truck', 'pink', 'eyebrow_shipped', [2, null], false, true, $kitTrack],
        'completed' => ['gift', 'green', 'eyebrow_delivered', [3, null], false, false, null],
        'cancelled' => ['cross', 'red', 'eyebrow_cancelled', [1, __('email.kit.step_cancelled')], true, false, [__('email.kit.shop_again'), \App\Support\Url::external('/shop/'), null]],
        'refunded' => ['back', 'green', 'eyebrow_refunded', null, true, false, [$ctaLabel, $ctaUrl, null]],
        'failed' => ['card', 'red', 'eyebrow_failed', [0, __('email.kit.step_not_paid')], true, false, [$ctaLabel, $ctaUrl, __('email.reminder.button_note')]],
    ][$status] ?? ['heart', 'pink', 'eyebrow_processing', null, true, true, [$ctaLabel, $ctaUrl, null]];

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
    $kitHero = [$kitShape[0], $kitShape[1], __('email.kit.' . $kitShape[2]), $heading,
        $status === 'cancelled' ? __('email.order_status.cancelled_body') : $body];
    $kitTracker = $kitShape[3];
    $kitShowTotals = $kitShape[4];
    $kitShowInfo = $kitShape[5];
    $kitCta = $kitShape[6];
    $kitDue = $status === 'failed';
    $kitPaid = false;
@endphp

@section('kit_before')
@if ($status === 'onhold')
@include('emails.kit.notice', ['tone' => 'amber', 'html' => new \Illuminate\Support\HtmlString(($note !== '' ? '<b>' . e(__('email.order_status.onhold_need')) . '</b> ' . nl2br(e($note)) . ' ' : '') . e(__('email.order_status.onhold_reply')))])
@elseif ($trackable && in_array($status, ['shipped', 'completed'], true))
@include('emails.kit.notice', ['tone' => 'pink', 'html' => new \Illuminate\Support\HtmlString('<b>' . e(__('email.order_status.tracking_number', ['number' => $order['number']])) . '</b><br><span style="font-size:13px;color:#5E545A;">' . e(__('email.order_status.tracking_where')) . '</span>')])
@elseif ($status === 'cancelled' && $refundSentence !== '')
@include('emails.kit.notice', ['tone' => 'red', 'html' => $refundSentence])
@endif
@endsection
