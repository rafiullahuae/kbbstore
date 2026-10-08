@php
/*
    A product rail of the row-55 homepage: Best Sellers, Trending, Under AED 54.
                                                                     (Lane HA)
    $key    the HomepageSections key ('bestselling' | 'trending' | 'under54')
    $r      HomeSections::rail() for it
    $items  the products, at most max(laptop count, phone count)

    THE CARDS ARE partials/home/grid → <x-product-card>, at the shop's own
    grid skin (`skin => null` is GridSkins::resolve(null): the store-wide
    setting the category pages use). This file styles the BAND around them —
    heading, background, spacing, columns — and nothing inside a card.
    `hs-dc-N` / `hs-mc-N` hide the cards past N per device with constant
    :nth-child rules in kbb.css; the columns are --hs-cols-d/-m, integers from
    a select. No script.
*/
@endphp
<section class="sec {{ $r['classes'] }} {{ $cls }}" style="{{ $r['style'] }}"@if ($r['title'] !== '') aria-labelledby="hs-{{ $key }}-h"@endif><div class="wrap">
@include('partials.home.hs-head', ['hid' => 'hs-'.$key.'-h', 'h' => $r])
<div class="hs-grid">@include('partials.home.grid', ['items' => $items, 'skin' => null, 'catLabel' => null, 'aboveFold' => ($onTop ?? false) ? $r['above'] : 0])</div>
@include('partials.home.hs-foot', ['h' => $r])
{!! \App\Support\HomeSections::itemList($r['title'] !== '' ? $r['title'] : $key, $items->map(fn ($p) => ['url' => $p->url(), 'name' => (string) $p->t('name')])) !!}
</div></section>
