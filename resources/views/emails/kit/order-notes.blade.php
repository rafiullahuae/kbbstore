{{--
    The customer's own words on an order: the gift message and the order note
    (Lane EM). The approved previews' fixture has neither, so the kit has no
    block for them; they ride in the kit's notice box, pink for the gift and
    neutral for the note, under the delivery details -- where the template
    this replaced printed them. Customer text: {{ }} only, line breaks kept
    by nl2br over the escaped text.
--}}
@php
    $onLines = static fn (string $heading, string $text) => new \Illuminate\Support\HtmlString('<b>' . e($heading) . '</b><br>' . nl2br(e($text)));
@endphp
@if (($order['giftNote'] ?? '') !== '')
@include('emails.kit.notice', ['tone' => 'pink', 'html' => $onLines(__('email.delivery.gift_heading'), $order['giftNote'])])
@endif
@if (($order['customerNote'] ?? '') !== '')
@include('emails.kit.notice', ['tone' => 'ink', 'html' => $onLines(__('email.delivery.note_heading'), $order['customerNote'])])
@endif
