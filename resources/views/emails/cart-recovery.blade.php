{{--
    "Your basket is waiting" in look A — Lane EM, from the owner's approved
    preview 13. The message ($body) is the owner's, from Store → Modules →
    Basket reminder. Lines carry the basket's own names, quantities and prices;
    only the picture and the brand are looked up (KitProducts::forSlugs, one
    query).
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.kit.basket_title');
    $kitPreheader = __('email.kit.pre_basket');
    $kitWhy = __('email.kit.why_basket');
    $kitUnsubscribe = $unsubscribeUrl;
    $kitTemplate = 'cart_recovery';
    $kitLead = new \Illuminate\Support\HtmlString(nl2br(e($body)));
    $kitFound = \App\Services\Mail\Kit\KitProducts::forSlugs(array_map(static fn (array $i) => (string) ($i['slug'] ?? ''), $items));
    $kitLines = array_map(static function (array $i) use ($kitFound) {
        $qty = (int) ($i['quantity'] ?? 0);
        $unit = (int) ($i['unit_price'] ?? 0);
        $found = $kitFound[(string) ($i['slug'] ?? '')] ?? ['img' => null, 'brand' => ''];

        return [
            'img' => $found['img'], 'brand' => $found['brand'], 'name' => (string) ($i['name'] ?? ''), 'variant' => '',
            'qty' => $qty, 'unit' => \App\Services\Mail\Kit\MailKit::money($unit), 'total' => \App\Services\Mail\Kit\MailKit::money($unit * $qty),
            // What is in a set, one member per line (Lane SE), read live at
            // send time by CartRecovery::basket(); quantities and names only.
            'sub' => array_values(array_map('strval', (array) ($i['setContents'] ?? []))),
            // Absolute: an inbox has no origin to resolve /product/… against.
            'href' => ($i['slug'] ?? '') !== '' ? \App\Support\Url::external('/product/' . $i['slug'] . '/') : null,
        ];
    }, $items);
    // (Lane FGE) The free gifts this basket's total reaches today, in the
    // checkout's opened box, with the owner's line that they depend on
    // availability when the order is placed. Nothing while gifts are off or
    // the basket reaches no range; the gift lookup is FreeGifts' own cached one.
    $kitFgSub = array_sum(array_map(static fn (array $i) => (int) ($i['unit_price'] ?? 0) * (int) ($i['quantity'] ?? 0), $items));
    $kitGiftLines = array_map(static fn (array $r) => [
        'img' => \App\Services\Mail\Kit\MailKit::image($r['image'] !== '' ? $r['image'] : null, 200, false),
        'brand' => $r['brand'], 'name' => $r['name'], 'qty' => (int) $r['quantity'],
    ], \App\Services\FreeGifts::rows(app(\App\Services\FreeGifts::class)->forTotals(['subtotal' => $kitFgSub, 'item_count' => count($items)])));
@endphp

@section('kit_inner')
@kitsec('hero')
@include('emails.kit.hero', ['icon' => 'bag', 'tone' => 'pink', 'eyebrow' => __('email.kit.eyebrow_basket'), 'title' => __('email.kit.basket_title'), 'lead' => $kitLead])
@endkitsec
@kitsec('items')
@if ($kitLines !== [])
@include('emails.kit.items', ['lines' => $kitLines, 'showPrice' => true])
@endif
@include('emails.kit.gift-box', ['lines' => $kitGiftLines, 'note' => app(\App\Services\FreeGifts::class)->text('availability'), 'cta' => app(\App\Services\FreeGifts::class)->text('basket_cta')])
@endkitsec
@kitsec('button')
@include('emails.kit.button', ['label' => __('email.kit.basket_button'), 'href' => $cartUrl])
@endkitsec
@kitsec('note')
@include('emails.kit.para', ['html' => __('email.cart_recovery.why'), 'pad' => '18px 32px 28px', 'size' => 13])
@endkitsec
@endsection
