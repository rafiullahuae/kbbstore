{{--
    GIFTS CRITERIA AS SIMPLE ROWS (Lane GCR). The owner, 15 November: "open as
    same popup, but with same section which we have on homepage, only the rows
    having gifts icons etc, without any arrow button or further clicks. just
    simple display rows". Gift box popups -> Gifts Criteria popup shows ->
    Simple rows (the default, as he asked; "Complete tabs" is the design
    picked beside it, exactly as before).

    The same popover the button already opens (same header, same close; Esc
    and a tap outside close it), whose body is the homepage section's card
    (partials/home/free-gifts: .fgh-card / .fgh-r) drawn as plain rows: the
    amount, the wording, the FREE coupon and the gift photos, "+N" past the
    photos that fit. No chevron, no buttons, no tabs, nothing to click. The
    bands are FreeGifts::bandRows(), the rows the homepage reads, already in
    $kbbFgX: nothing is fetched here. A long list scrolls inside .gbx-cb.
    On a phone the wording sits under the amount (the homepage hides it
    there because its popup shows it; this has no popup behind it).

    In (from extras.blade.php): $kbbFgX, $kbbFg, $gbxT, $gbxGift, $gbxClose, $gbxOpen.
--}}@php
    $gbxRi = static function (array $g): string {
        $img = (string) $g['image'];
        if ($img === '') {
            return '<span class="gbx-rh" role="img" aria-label="' . e($g['name']) . '">' . e(mb_strtoupper(mb_substr($g['brand'] !== '' ? $g['brand'] : $g['name'], 0, 2))) . '</span>';
        }
        $set = \App\Support\ImageVariants::srcsetFor($img);

        return '<img src="' . e(\App\Support\ImageVariants::variantUrl($img, 200)) . '" alt="' . e(trim($g['brand'] . ' ' . $g['name'])) . '" width="42" height="42" loading="lazy" decoding="async"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="42px"' : '') . '>';
    };
@endphp<div class="gbx-cp gbx-cR" popover role="dialog" aria-label="{{ $gbxT['crit'] }}"{!! $gbxOpen !!}><header class="gbx-ch"><h3>{!! $gbxGift !!}{{ $gbxT['crit'] }}</h3><p>{{ $gbxT['sub'] }}</p>{!! $gbxClose !!}</header><div class="gbx-cb"><div class="gbx-rw">@foreach ($kbbFgX['bands'] as $gbxB)@php $gbxN = count($gbxB['gifts'] ?? $gbxB['thumbs']); @endphp<div class="gbx-r"><span class="gbx-ra">{{ $gbxB['amount'] }}</span><span class="gbx-rd">{{ $gbxB['words'] }}</span><span class="gbx-rf">{{ $kbbFg->text('free') }}</span><span class="gbx-rp">@foreach (array_slice($gbxB['thumbs'], 0, 6) as $gbxTh)<span class="gbx-rt">{!! $gbxRi($gbxTh) !!}@if ((int) $gbxTh['quantity'] > 1)<b>×{{ (int) $gbxTh['quantity'] }}</b>@endif</span>@endforeach @if ($gbxN > 6)<span class="gbx-rm d">+{{ $gbxN - 6 }}</span>@endif @if ($gbxN > 5)<span class="gbx-rm m">+{{ $gbxN - 5 }}</span>@endif</span></div>@endforeach</div></div></div>
