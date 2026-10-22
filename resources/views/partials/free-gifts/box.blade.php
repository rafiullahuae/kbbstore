{{--
    THE FREE GIFTS BOX -- the owner's final design, "02 · Mint outline" (Lane FG).

    The spec is docs/freebies-options/box-02-final.html, and these are its
    values (`.gb` + `.gb.n2` and the open-list rules): a white card, 1.5px
    #2e8f62 outline, 12px corners; "Free gifts for you" ON the border at
    14.5px/700 green with a 16px gift icon (13.5px where the surface is 345px or
    narrower, i.e. phones under ~380px), its label filled with the colour of the
    surface behind it (#fbf7f8 in the checkout summary, see --fg-surface below).

    CLOSED (the default): the band's wording at 12.5px/600 and " · N gifts" in
    muted 500; a row of 36px photos (8px corners) with a green "×N" badge when
    the quantity is above 1; "View all" (11.5px/700, green).
    OPENED: the same wording line, a 1px dashed green divider, one row per gift
    -- a 28px photo (7px corners), the brand at 10px capitals, muted, the name at
    12px with "N× " bold, and "FREE" at 11px/800 green on the right, always --
    then "Hide". With Marketing -> Free gifts -> Display -> "Show gift prices"
    on (it ships OFF) each row adds the gift's price, struck through and muted,
    before FREE. No total row, no value badge, no "add AED X more" nudge.

    "GIFT BOX LOOK" (Lane FG5, Display -> Gift box look): the sizes above are
    the defaults of custom properties on the fieldset -- --fg-pr closed photo,
    --fg-pl list photo, --fg-ts title size, --fg-rg row gap, --fg-fg space
    before FREE (a margin on the FREE column, so a title can never touch it) --
    and the switches are fixed classes: fgb-nobrand, fgb-clamp2 (two lines,
    -webkit-line-clamp over a plain max-height), fgb-fade (one line, masked
    out at the title's own end: each title gets dir="ltr"/"rtl" from
    FreeGifts::textDir(), so an Arabic name on an Arabic page fades on the
    left; a title in the other direction from the page -- an English name on
    /ar/ -- sits on the page's side and ends in "…" only when it is too long,
    keeping its first words; a fade there would fade every short name), fgb-sep (a hairline between rows).
    Both come from FreeGifts::lookAttrs(): constants and integers, never a
    string from a setting. Shipped defaults are today's look except the two
    the owner asked for: 16px before FREE (was 8) and two lines then "…".

    Drawn in the cart panel, on the cart page, in the checkout summary, on the
    order-received page and on My account -> Orders, from the same rows
    (FreeGifts::rows() / ::orderRows()). Emits NOTHING -- not a byte -- when
    there are no rows, which is every basket while the switch is off.

    A <fieldset>: its <legend> sits on the border by itself, at the border's
    own height in every browser, with no positioning to measure.

    View all / Hide toggles the markup already here (the hidden attribute, an
    inline handler): no request, no script file, no layout measuring. Each
    button carries aria-expanded; focus moves to the other one. No ids, because
    the checkout draws its order block twice from one string.

    Every name, brand and text is escaped; the texts are the owner's settings.
    Pictures offer their ImageVariants copies at 36px; never the bare original.
--}}@if (($kbbFgRows ?? []) !== [])@php
    $kbbFg = app(\App\Services\FreeGifts::class);
    $kbbFgPrices = ($kbbFgShowValue ?? $kbbFg->config()['show_value']) === true;
    $kbbFgUnits = array_sum(array_column($kbbFgRows, 'quantity'));
    $kbbFgLookCfg = \App\Services\FreeGifts::lookOf($kbbFg->config()['look'] ?? null);
    $kbbFgLook = \App\Services\FreeGifts::lookAttrs($kbbFgLookCfg);
    $kbbFgTile = static function (array $row, string $sizes): string {
        $img = $row['image'];
        if ($img === '') {
            return '<span class="fgb-t"><span class="fgb-ph" role="img" aria-label="' . e($row['name']) . '">'
                . e(mb_strtoupper(mb_substr($row['brand'] !== '' ? $row['brand'] : $row['name'], 0, 2))) . '</span>';
        }
        $set = \App\Support\ImageVariants::srcsetFor($img);
        return '<span class="fgb-t"><img src="' . e(\App\Support\ImageVariants::variantUrl($img, 200)) . '" alt="' . e($row['name'])
            . '" width="' . $sizes . '" height="' . $sizes . '" loading="lazy" decoding="async"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . $sizes . 'px"' : '') . '>';
    };
@endphp<fieldset class="fgb{{ ($kbbFgClass ?? '') === 'fgb-page' ? ' fgb-page' : '' }}{{ $kbbFgLook['class'] }}" style="{{ $kbbFgLook['style'] }}"><style>.fgb{margin:18px 0 4px;padding:2px 11px 9px;border:1.5px solid #2e8f62;border-radius:12px;background:#fff;color:#2a2228;min-width:0}.fgb [hidden]{display:none!important}.kbb-checkout .summary{--fg-surface:#fff}.kbb-checkout.cop-sumg .summary{--fg-surface:#EEF8F1}.kbb-checkout.cop-sumg.cop-sog .summary{--fg-surface:#F4FAF6}.kbb-checkout.cop-sumg.cop-sow .summary{--fg-surface:#FBFDFC}.kbb-checkout.cop-sumg.cop-sob .summary{--fg-surface:#fbf7f8}.fgb legend{display:flex;align-items:center;gap:7px;padding:0 7px;margin-inline-start:-1px;background:var(--fg-surface,#fff);color:#2e8f62;font-size:14.5px;font-weight:700;line-height:1.45}.fgb legend svg{width:16px;height:16px;flex:none}.fgb-w{font-size:12.5px;font-weight:600;margin-top:3px;color:#2a2228}.fgb-cnt{font-weight:500;color:#756c74}.fgb-th{display:flex;gap:5px;margin-top:7px;flex-wrap:wrap}.fgb-t{position:relative;display:block;width:var(--fg-pr,36px);height:var(--fg-pr,36px);border-radius:8px;background:#fff;border:1px solid rgba(42,34,40,.08);flex:none;box-sizing:border-box}.fgb-t img{display:block;width:100%;height:100%;object-fit:contain;border-radius:8px}.fgb-ph{display:grid;place-items:center;width:100%;height:100%;font-size:10.5px;font-weight:800;color:#8a7d86;background:repeating-linear-gradient(135deg,#faf5f7 0 6px,#f3ebef 6px 12px);border-radius:8px;letter-spacing:.02em}.fgb-t b{position:absolute;top:-6px;inset-inline-end:-6px;background:#2e8f62;color:#fff;font-size:9.5px;font-weight:800;border-radius:999px;min-width:17px;height:17px;padding:0 4px;box-sizing:border-box;display:grid;place-items:center;line-height:1}.fgb .fgb-tg{border:0;background:none;padding:0;color:#2e8f62;font:inherit;font-size:11.5px;font-weight:700;margin-inline-start:4px;align-self:center;min-height:32px;cursor:pointer}.fgb .fgb-tg:focus-visible{outline:2px solid #2e8f62;outline-offset:2px;border-radius:4px}.fgb-list{margin-top:7px;border-top:1px dashed #2e8f62;padding-top:5px;display:grid;gap:var(--fg-rg,3px)}.fgb-lr{display:flex;align-items:center;gap:8px}.fgb-lr .fgb-t{width:var(--fg-pl,28px);height:var(--fg-pl,28px);border-radius:7px}.fgb-lr .fgb-t img,.fgb-lr .fgb-ph{border-radius:7px}.fgb-ln{flex:1;min-width:0;font-size:var(--fg-ts,12px);line-height:1.25;color:#2a2228}.fgb-nm{display:block}.fgb-ln small{display:block;font-size:10px;color:#756c74;text-transform:uppercase;letter-spacing:.04em}.fgb-ln em{font-style:normal;font-weight:700}.fgb-lv{display:flex;gap:6px;align-items:baseline;font-size:11px;font-weight:800;color:#2e8f62;white-space:nowrap;margin-inline-start:calc(var(--fg-fg,16px) - 8px)}.fgb-lv s{color:#756c74;font-weight:500}.fgb .fgb-ls .fgb-tg{display:block;margin:6px 0 0;min-height:24px}fieldset.fgb-page{margin:18px 14px 14px}.kbb-freeship-slot>.fgb:first-child{margin-top:12px}@media(max-width:380px){.fgb legend{font-size:13.5px}}@if ($kbbFgLookCfg['titles'] === 'clamp2').fgb-clamp2 .fgb-nm{max-height:2.5em;overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2}@elseif ($kbbFgLookCfg['titles'] === 'fade').fgb-fade .fgb-list{grid-template-columns:minmax(0,1fr)}.fgb-fade .fgb-nm{white-space:nowrap;overflow:hidden;-webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent);mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent)}.fgb-fade .fgb-nm[dir=rtl]{-webkit-mask-image:linear-gradient(270deg,#000 calc(100% - 28px),transparent);mask-image:linear-gradient(270deg,#000 calc(100% - 28px),transparent)}[dir=rtl] .fgb-fade .fgb-nm[dir=ltr]{-webkit-mask-image:none;mask-image:none;text-overflow:ellipsis;text-align:right}[dir=ltr] .fgb-fade .fgb-nm[dir=rtl]{-webkit-mask-image:none;mask-image:none;text-overflow:ellipsis;text-align:left}@endif
@if (! $kbbFgLookCfg['brand']).fgb-nobrand .fgb-ln small{display:none}@endif
@if ($kbbFgLookCfg['separator']).fgb-sep .fgb-lr+.fgb-lr{border-top:1px solid #e4eee9;padding-top:var(--fg-rg,3px)}@endif
</style><legend><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg><span>{{ $kbbFg->text('title') }}</span></legend><div class="fgb-w">@if (($kbbFgWords ?? '') !== ''){{ $kbbFgWords }} <span class="fgb-cnt">· {{ $kbbFg->count($kbbFgUnits) }}</span>@else<span class="fgb-cnt">{{ $kbbFg->count($kbbFgUnits) }}</span>@endif</div><div class="fgb-th">@foreach ($kbbFgRows as $kbbFgRow){!! $kbbFgTile($kbbFgRow, (string) (int) $kbbFgLookCfg['photo_row']) !!}@if ($kbbFgRow['quantity'] > 1)<b>×{{ $kbbFgRow['quantity'] }}</b>@endif</span>@endforeach<button type="button" class="fgb-tg" aria-expanded="false" onclick="var f=this.closest('.fgb');f.querySelector('.fgb-th').hidden=true;f.querySelector('.fgb-ls').hidden=false;f.querySelector('.fgb-ls .fgb-tg').focus()">{{ $kbbFg->text('view_all') }}</button></div><div class="fgb-ls" hidden><div class="fgb-list">@foreach ($kbbFgRows as $kbbFgRow)<div class="fgb-lr">{!! $kbbFgTile($kbbFgRow, (string) (int) $kbbFgLookCfg['photo_list']) !!}</span><span class="fgb-ln">@if ($kbbFgRow['brand'] !== '')<small>{{ $kbbFgRow['brand'] }}</small>@endif<span class="fgb-nm"@if ($kbbFgLookCfg['titles'] === 'fade') dir="{{ \App\Services\FreeGifts::textDir($kbbFgRow['name']) }}"@endif>@if ($kbbFgRow['quantity'] > 1)<em>{{ $kbbFgRow['quantity'] }}× </em>@endif{{ $kbbFgRow['name'] }}</span></span><span class="fgb-lv">@if ($kbbFgPrices && $kbbFgRow['value'] > 0)<s>{{ \App\Support\Money::plain($kbbFgRow['value']) }}</s>@endif{{ $kbbFg->text('free') }}</span></div>@endforeach</div><button type="button" class="fgb-tg" aria-expanded="true" onclick="var f=this.closest('.fgb');f.querySelector('.fgb-ls').hidden=true;f.querySelector('.fgb-th').hidden=false;f.querySelector('.fgb-th .fgb-tg').focus()">{{ $kbbFg->text('hide') }}</button></div></fieldset>@endif
