{{--
    THE TOP STRIP — Appearance → Homepage content → Top strip (Lane HC).
    App\Support\HomeStrips carries the reasoning. One element, its colours and
    sizes as custom properties Blade escapes; the stylesheet is a constant.
    Shown per device by its Homepage row: `d-off` hides it from 901px, so a
    laptop is sent this same markup and never sees it.

    $tsOut is HTML built from ESCAPED parts: the owner's wording through e(),
    the shipped first half through e(), and the free-delivery half the way the
    hero's delivery band prints the same key — a translation whose only
    placeholder is Money::format() of a figure the shop computed.

    ONE <span> AROUND THE LINE: `.kts` is a flex row, and a flex container
    turns each text run and each inline element into its own item, dropping
    the space between them — "Free Delivery overAED 199" in the first shot.
--}}@php
    if ($ts['text'] !== null) {
        $tsOut = e($ts['text']);
    } else {
        $tsFree = app(\App\Services\ShippingService::class)->thresholdHere();
        $tsOut = implode(' – ', array_filter([
            \App\Support\DeliveryLine::here() !== '' ? e(__('store.topstrip.text')) : '',
            $tsFree !== null ? __('store.topstrip.free_over', ['amount' => \App\Support\Money::format($tsFree, 0)]) : '',
        ]));
    }
@endphp
@if ($tsOut !== '' && $ts['url'] !== ''){!! \App\Support\HomeStrips::CSS !!}<a class="{{ trim('kts '.$cls) }}" style="{{ $ts['style'] }}" href="{{ $ts['url'] }}"><span>{!! $tsOut !!}</span></a>
@elseif ($tsOut !== ''){!! \App\Support\HomeStrips::CSS !!}<div class="{{ trim('kts '.$cls) }}" style="{{ $ts['style'] }}"><span>{!! $tsOut !!}</span></div>
@endif
