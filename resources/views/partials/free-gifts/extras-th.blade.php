{{--
    GIFT BOX POPUPS -- the photo row (Lane GBX), in place of the plain one only
    while the popups are on for this request. Each photo is a button that opens
    its gift's popup (A, B, D: a popover drawn by extras.blade.php; C: a card
    inside the box, under the photos, its pointer placed by calc() from the
    photo's index). View all is the same button with the same handler; Gifts
    Criteria sits on the right of it. Nothing measured, nothing fetched.

    In: $kbbFgX, $kbbFgThumbs/$kbbFgRows, $kbbFgTile, $kbbFgRowPx, $kbbFgCrit, $kbbFg, $kbbFgTier.
--}}@php
    $gbxPop = $kbbFgX['cfg']['pop'];
    $gbxCols = $kbbFgTier === ' fgb-g5' ? 5 : ($kbbFgTier === ' fgb-g4' ? 4 : 0);
@endphp
@if ($gbxPop === 'off')
{{-- 2.60.623, the owner: "the products thumbnails inside the gift box should
     give list upon click on them" -- each photo opens the list, as View all. --}}
@foreach ($kbbFgThumbs ?? $kbbFgRows as $kbbFgRow){!! '<button type="button" class="fgb-t" aria-label="' . e($kbbFgRow['name']) . '" aria-expanded="false" onclick="var f=this.closest(\'.fgb\');f.querySelector(\'.fgb-th\').hidden=true;f.querySelector(\'.fgb-ls\').hidden=false;if(!event.detail)f.querySelector(\'.fgb-ls .fgb-tg\').focus()">' . substr($kbbFgTile($kbbFgRow, (string) (int) $kbbFgRowPx), strlen('<span class="fgb-t">')) !!}@if ($kbbFgRow['quantity'] > 1)<b>×{{ $kbbFgRow['quantity'] }}</b>@endif</button>@endforeach
@else
@foreach ($kbbFgThumbs ?? $kbbFgRows as $gbxI => $kbbFgRow)@php
    $gbxTile = $kbbFgTile($kbbFgRow, (string) (int) $kbbFgRowPx);
    $gbxOn = $gbxPop === 'C'
        ? "var b=this.closest('.fgb'),k=b.querySelectorAll('.gbx-k'),c=k[" . $gbxI . "];k.forEach(function(x){if(x!==c)x.hidden=true});c.hidden=!c.hidden;this.setAttribute('aria-expanded',String(!c.hidden));if(!c.hidden)c.querySelector('.gbx-x').focus()"
        : "var p=this.closest('.fgb').querySelectorAll('.gbx-pp')[" . $gbxI . "];if(p&&p.showPopover){p.gbxFrom=this;p.showPopover();p.querySelector('.gbx-x').focus()}";
@endphp
{!! '<button type="button" class="fgb-t" aria-label="' . e($kbbFgRow['name']) . '"' . ($gbxPop === 'C' ? ' aria-expanded="false"' : ' aria-haspopup="dialog"') . ' onclick="' . e($gbxOn) . '">' . substr($gbxTile, strlen('<span class="fgb-t">')) !!}@if ($kbbFgRow['quantity'] > 1)<b>×{{ $kbbFgRow['quantity'] }}</b>@endif</button>@endforeach
@endif
@if ($gbxPop === 'C')@php
    $gbxAr = ! \App\Support\Locale::isDefault();
    $gbxT = \App\Services\FreeGifts::EXTRA_TEXTS[$gbxAr ? 'ar' : 'en'];
@endphp
@foreach ($kbbFgX['gifts'] as $gbxI => $gbxG)@php
    $gbxBack = "this.closest('.fgb').querySelectorAll('.fgb-th .fgb-t')[" . $gbxI . "]";
    $gbxSet = $gbxG['image'] !== '' ? \App\Support\ImageVariants::srcsetFor($gbxG['image']) : '';
@endphp<div class="gbx-k" hidden role="dialog" aria-label="{{ $gbxG['name'] }}" style="--gi:{{ $gbxCols > 0 ? $gbxI % $gbxCols : $gbxI }}" onkeydown="{{ "if(event.key==='Escape'){event.stopPropagation();this.hidden=true;var t=" . $gbxBack . ";t.setAttribute('aria-expanded','false');t.focus()}" }}"><span class="gbx-ka" aria-hidden="true"></span>@if ($gbxG['image'] !== '')<img class="gbx-im" src="{{ \App\Support\ImageVariants::variantUrl($gbxG['image'], 200) }}" alt="" width="64" height="64" loading="lazy" decoding="async"@if ($gbxSet !== '') srcset="{{ $gbxSet }}" sizes="{{ (int) $kbbFgRowPx }}px"@endif>@else<span></span>@endif<div class="gbx-tx">@if ($gbxG['brand'] !== '')<small>{{ $gbxG['brand'] }}</small>@endif<b class="gbx-nm">{{ $gbxG['name'] }}</b><p class="gbx-pr">@if ($gbxG['now'] < $gbxG['reg'])<s>{{ \App\Support\Money::plain($gbxG['reg']) }}</s> @endif<b>{{ \App\Support\Money::plain($gbxG['now']) }}</b></p></div><span class="gbx-kf">{{ $kbbFg->text('free') }}</span><button type="button" class="gbx-x" aria-label="{{ $gbxT['close'] }}" onclick="{{ "var c=this.closest('.gbx-k');c.hidden=true;var t=c.closest('.fgb').querySelectorAll('.fgb-th .fgb-t')[" . $gbxI . "];t.setAttribute('aria-expanded','false');t.focus()" }}"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>@endforeach
@endif
<span class="gbx-ft"><button type="button" class="fgb-tg" aria-expanded="false" onclick="var f=this.closest('.fgb');f.querySelector('.fgb-th').hidden=true;f.querySelector('.fgb-ls').hidden=false;f.querySelector('.fgb-ls .fgb-tg').focus()">{{ $kbbFg->text('view_all') }}</button>{!! $kbbFgCrit !!}</span>
