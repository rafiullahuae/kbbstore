{{--
    GIFT BOX POPUPS -- the popups (Lane GBX), drawn inside the box after its
    list, only while Marketing -> Free gifts -> Overview -> Gift box popups is
    on for this request (FreeGifts::extrasFor()). Everything is already here:
    nothing is fetched to open a popup. A, B, D and Gifts Criteria are
    popovers (Esc and a tap outside close them, nothing behind them moves);
    each photo's popup shows the photo the box already loaded (the same src,
    srcset and sizes, so the browser reuses it). Inline handlers like View
    all's own: no script file, no layout measuring, focus goes back to what
    opened it. Every value is escaped; the designs are fixed classes.

    In: $kbbFgX (FreeGifts::extrasBox()), $kbbFg, $kbbFgRowPx.
--}}@php
    $gbxAr = ! \App\Support\Locale::isDefault();
    $gbxT = \App\Services\FreeGifts::EXTRA_TEXTS[$gbxAr ? 'ar' : 'en'];
    $gbxX = '<span aria-hidden="true">✕</span>';
    $gbxGift = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';
    // The photo as the box drew it: same src, srcset and sizes, so it comes from the browser's memory.
    $gbxImg = static function (array $g, int $w, string $sizes) {
        if ($g['image'] === '') {
            return '';
        }
        $set = \App\Support\ImageVariants::srcsetFor($g['image']);
        return '<img class="gbx-im" src="' . e(\App\Support\ImageVariants::variantUrl($g['image'], 200)) . '" alt="" width="' . $w . '" height="' . $w . '" loading="lazy" decoding="async"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . e($sizes) . 'px"' : '') . '>';
    };
    $gbxPrice = static function (array $g, bool $free) use ($gbxT, $kbbFg) {
        if ($free) {
            return '<p class="gbx-pr"><span class="gbx-vl">' . e($gbxT['value']) . '</span> ' . ($g['reg'] > 0 ? '<s>' . e(\App\Support\Money::plain($g['reg'])) . '</s> ' : '') . '<b class="gbx-fr">' . e($kbbFg->text('free')) . '</b></p>';
        }
        return '<p class="gbx-pr">' . ($g['now'] < $g['reg'] ? '<s>' . e(\App\Support\Money::plain($g['reg'])) . '</s> ' : '') . '<b>' . e(\App\Support\Money::plain($g['now'])) . '</b></p>';
    };
    $gbxPop = $kbbFgX['cfg']['pop'];
    $gbxClose = '<button type="button" class="gbx-x" aria-label="' . e($gbxT['close']) . '" onclick="this.closest(\'[popover]\').hidePopover()">' . $gbxX . '</button>';
    $gbxOpen = ' onkeydown="if(event.key===\'Escape\')event.stopPropagation()" ontoggle="if(event.newState===\'closed\'&&this.gbxFrom)this.gbxFrom.focus()"';
@endphp
@if ($gbxPop !== 'C' && $gbxPop !== 'off')@foreach ($kbbFgX['gifts'] as $gbxG)<div class="gbx-pp gbx-p{{ $gbxPop }}" popover role="dialog" aria-label="{{ $gbxG['name'] }}"{!! $gbxOpen !!}>{!! $gbxClose !!}@if ($gbxPop === 'A')<div class="gbx-a">{!! $gbxImg($gbxG, 112, (string) (int) $kbbFgRowPx) !!}<div class="gbx-tx">@if ($gbxG['brand'] !== '')<small>{{ $gbxG['brand'] }}</small>@endif<h3>{{ $gbxG['name'] }}</h3>{!! $gbxPrice($gbxG, false) !!}<span class="gbx-tag">{{ $gbxT['free_with'] }}</span></div></div>@elseif ($gbxPop === 'B')<figure class="gbx-b-ph">{!! $gbxImg($gbxG, 320, (string) (int) $kbbFgRowPx) !!}<span class="gbx-badge">{{ $kbbFg->text('free') }}</span></figure><div class="gbx-b-tx">@if ($gbxG['brand'] !== '')<small>{{ $gbxG['brand'] }}</small>@endif<h3>{{ $gbxG['name'] }}</h3>{!! $gbxPrice($gbxG, false) !!}<p class="gbx-note">{{ $gbxT['free_with'] }}</p></div>@else<div class="gbx-dd">{!! $gbxImg($gbxG, 180, (string) (int) $kbbFgRowPx) !!}@if ($gbxG['brand'] !== '')<small>{{ $gbxG['brand'] }}</small>@endif<h3>{{ $gbxG['name'] }}</h3>{!! $gbxPrice($gbxG, true) !!}<p class="gbx-dl">{{ $gbxT['free_with'] }}</p></div>@endif</div>@endforeach @endif
@if ($kbbFgX['bands'] !== [])@php $gbxD = $kbbFgX['cfg']['crit']; $gbxName = 'gbx' . \Illuminate\Support\Str::random(8); $gbxCur = (int) $kbbFgX['cur']; @endphp<div class="gbx-cp gbx-c{{ $gbxD }}" popover role="dialog" aria-label="{{ $gbxT['crit'] }}"{!! $gbxOpen !!}><header class="gbx-ch"><h3>{!! $gbxGift !!}{{ $gbxT['crit'] }}</h3><p>{{ $gbxT['sub'] }}</p>{!! $gbxClose !!}</header><div class="gbx-cb">@if ($gbxD === '1')<ol class="gbx-1">@foreach ($kbbFgX['bands'] as $gbxI => $gbxB)<li class="{{ $gbxI === $gbxCur ? 'on' : '' }}"><div class="gbx-1h"><span class="gbx-am">{{ $gbxB['amount'] }}</span>@if ($gbxI === $gbxCur)<span class="gbx-you">{{ $gbxT['yours'] }}</span>@else<span class="gbx-cn">{{ $gbxB['count'] }}</span>@endif</div><p class="gbx-wd">{{ $gbxB['words'] }}</p><div class="gbx-th">@foreach (array_slice($gbxB['thumbs'], 0, 6) as $gbxTh)<span class="gbx-t">{!! $gbxImg($gbxTh, 40, '40') !!}@if ($gbxTh['quantity'] > 1)<b>×{{ $gbxTh['quantity'] }}</b>@endif</span>@endforeach @if (count($gbxB['thumbs']) > 6)<span class="gbx-more">+{{ count($gbxB['thumbs']) - 6 }}</span>@endif</div></li>@endforeach</ol>@elseif ($gbxD === '2')<ol class="gbx-2">@foreach ($kbbFgX['bands'] as $gbxI => $gbxB)<li class="{{ $gbxI === $gbxCur ? 'on' : ($gbxI < $gbxCur ? 'done' : '') }}"><span class="gbx-dot" aria-hidden="true">{{ $gbxI + 1 }}</span><span class="gbx-am">{{ $gbxB['amount'] }}</span><span class="gbx-wd">{{ $gbxB['words'] }}@if ($gbxI === $gbxCur) <em>{{ $gbxT['yours'] }}</em>@endif</span><span class="gbx-cn">{{ $gbxB['count'] }}</span></li>@endforeach</ol>@else<div class="gbx-3"><div class="gbx-tabs" role="radiogroup" aria-label="{{ $gbxT['crit'] }}">@foreach ($kbbFgX['bands'] as $gbxI => $gbxB)<label><input type="radio" name="{{ $gbxName }}" value="{{ $gbxI }}"@if ($gbxI === max(0, $gbxCur)) checked @endif><span>{{ $gbxB['amount'] }}@if ($gbxI === $gbxCur)<i>{{ $gbxT['yours'] }}</i>@endif</span></label>@endforeach</div>@foreach ($kbbFgX['bands'] as $gbxI => $gbxB)<div class="gbx-tp{{ $gbxI === max(0, $gbxCur) ? ' on' : '' }}" data-i="{{ $gbxI }}"><div><p class="gbx-wd">{{ $gbxB['words'] }} <span class="gbx-cn">· {{ $gbxB['count'] }}</span></p><div class="gbx-grid{{ count($gbxB['thumbs']) > 6 ? ' gbx-s3' : '' }}{{ count($gbxB['thumbs']) > 8 ? ' gbx-s4' : '' }}" style="--gc:{{ max(3, (int) ceil(count($gbxB['thumbs']) / 2)) }};--gd:{{ max(4, (int) ceil(count($gbxB['thumbs']) / 2)) }}">@foreach ($gbxB['thumbs'] as $gbxTh)<div class="gbx-gc"><span class="gbx-t">{!! $gbxImg($gbxTh, 72, '72') !!}@if ($gbxTh['quantity'] > 1)<b>×{{ $gbxTh['quantity'] }}</b>@endif</span><span class="gbx-gn">{{ $gbxTh['name'] }}</span></div>@endforeach</div></div></div>@endforeach</div>@endif</div></div>@endif
