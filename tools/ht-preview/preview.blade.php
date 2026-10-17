{{--
    Lane HT -- Product tabs, PREVIEW ONLY (tools/ht-router.php). Five designs
    over the same data: the real layout, the real <x-product-card>, the real
    grid/carousel classes (GridSections::css()) and the real tab switcher that
    already ships in ymal.js ([data-rp-tabs]) -- so the only new bytes here are
    the tab-row looks, scoped under .htp and kept in this file.
--}}
@extends('layouts.store')
@section('title', 'Product tabs preview '.strtoupper($v))
@push('styles'){!! \App\Services\GridSections::css() !!}<style>
.htp-badge{display:inline-block;margin:18px 0 6px;padding:5px 12px;border-radius:99px;background:#2A2228;color:#fff;font:600 12px/1.2 var(--sans);letter-spacing:.02em}
.htp .htp-tl{display:flex;align-items:flex-start;gap:8px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.htp .htp-tl::-webkit-scrollbar{display:none}
.htp .htp-t{flex:none;font:inherit;cursor:pointer;background:none;border:0;padding:0;color:var(--ink-2);white-space:nowrap}
.htp .htp-t:focus-visible{outline:2px solid var(--pink-deep);outline-offset:3px;border-radius:6px}
.htp .gs-foot{margin-top:16px}
.htp .htp-panel{min-width:0}

/* A -- underline text tabs, centred, carousel */
.htp-a .htp-tl{justify-content:center;gap:28px;border-bottom:1px solid var(--line);margin:0 auto 18px;max-width:max-content}
.htp-a .htp-t{font-size:15px;font-weight:500;padding:10px 2px 12px;border-bottom:2px solid transparent;margin-bottom:-1px}
.htp-a .htp-t[aria-selected="true"]{color:var(--ink);font-weight:700;border-bottom-color:var(--pink-deep)}
@media (max-width:900px){.htp-a .htp-tl{justify-content:flex-start;gap:22px;max-width:none;margin-inline:calc(var(--site-gutter,18px) * -1);padding-inline:var(--site-gutter,18px)}.htp-a .htp-t{font-size:14px}}

/* B -- pill chips, left, grid */
.htp-b .hs-head{text-align:start;margin-inline:0;max-width:none;margin-bottom:12px}
.htp-b .htp-tl{margin-bottom:18px}
.htp-b .htp-t{min-height:38px;padding:0 18px;border-radius:99px;border:1px solid var(--line);background:#fff;font-size:14px;font-weight:600;color:var(--ink)}
.htp-b .htp-t:hover{border-color:var(--ink)}
.htp-b .htp-t[aria-selected="true"]{background:var(--ink);border-color:var(--ink);color:#fff}
@media (max-width:900px){.htp-b .htp-tl{margin-inline:calc(var(--site-gutter,18px) * -1);padding-inline:var(--site-gutter,18px)}.htp-b .htp-t{min-height:36px;padding:0 15px;font-size:13.5px}}

/* C -- segmented control, centred, carousel with arrows */
.htp-c .htp-tl{gap:0;padding:4px;border-radius:99px;background:var(--pink-soft);margin:0 auto 20px;max-width:max-content}
.htp-c .htp-t{min-height:40px;padding:0 22px;border-radius:99px;font-size:14px;font-weight:600;color:var(--ink-2)}
.htp-c .htp-t[aria-selected="true"]{background:#fff;color:var(--pink-ink);box-shadow:var(--sh-s)}
@media (max-width:900px){.htp-c .htp-tl{display:grid;grid-template-columns:1fr 1fr;gap:4px;max-width:none;border-radius:18px}.htp-c .htp-t{min-height:38px;padding:0 8px;font-size:13px}}

/* D -- title left, tabs right, View all link in the head */
.htp-d .htp-hd{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:18px;border-bottom:1px solid var(--line);padding-bottom:12px}
.htp-d .htp-hd h2{margin:0;font-size:clamp(20px,2.2vw,28px);letter-spacing:-.02em}
.htp-d .htp-hd .hs-eyebrow{display:block;margin-bottom:4px}
.htp-d .htp-tl{gap:22px}
.htp-d .htp-t{font-size:14.5px;font-weight:500;padding:4px 0}
.htp-d .htp-t[aria-selected="true"]{color:var(--pink-deep);font-weight:700}
.htp-d .htp-t[aria-selected="true"]::after{content:"";display:block;height:2px;margin-top:4px;background:currentColor;border-radius:2px}
.htp-d .htp-all{font-size:13.5px;font-weight:600;color:var(--ink);text-decoration:underline;text-underline-offset:3px}
@media (max-width:900px){.htp-d .htp-hd{display:block;border:0;padding:0;margin-bottom:12px}.htp-d .htp-tl{margin:12px calc(var(--site-gutter,18px) * -1) 0;padding:0 var(--site-gutter,18px) 0;gap:8px}.htp-d .htp-t{min-height:34px;padding:0 14px;border-radius:99px;background:var(--pink-soft);font-size:13px;color:var(--ink)}.htp-d .htp-t[aria-selected="true"]{background:var(--pink-deep);color:#fff}.htp-d .htp-t[aria-selected="true"]::after{display:none}}

/* E -- the tabs ARE the headline, carousel */
.htp-e .hs-eyebrow{display:block;text-align:center;margin-bottom:6px}
.htp-e .htp-tl{justify-content:center;gap:26px;margin-bottom:18px}
.htp-e .htp-t{font-size:clamp(20px,2.4vw,30px);font-weight:600;letter-spacing:-.02em;color:rgba(42,34,40,.28);padding:2px 0}
.htp-e .htp-t:hover{color:var(--ink-2)}
.htp-e .htp-t[aria-selected="true"]{color:var(--ink)}
.htp-e .htp-t[aria-selected="true"]::after{content:"";display:block;width:8px;height:8px;margin:6px auto 0;border-radius:50%;background:var(--pink-deep)}
@media (max-width:900px){.htp-e .htp-tl{justify-content:flex-start;gap:18px;margin-inline:calc(var(--site-gutter,18px) * -1);padding-inline:var(--site-gutter,18px)}.htp-e .htp-t{font-size:20px}}
</style>@endpush
@php
    $names = ['a' => 'A · Underline tabs + carousel', 'b' => 'B · Pill chips + grid', 'c' => 'C · Segmented control + carousel with arrows', 'd' => 'D · Title left, tabs right, View all · grid on laptop, carousel on phone', 'e' => 'E · Big-type tabs as the headline + carousel'];
    // Per option: tabs shown, and layout per device.
    $cfg = [
        'a' => ['n' => 4, 'd' => 'carousel', 'm' => 'carousel', 'cd' => 5, 'cm' => 2, 'pd' => .3, 'pm' => .3, 'arr' => true],
        'b' => ['n' => 5, 'd' => 'grid', 'm' => 'grid', 'cd' => 4, 'cm' => 2, 'dn' => 8, 'mn' => 4, 'arr' => false],
        'c' => ['n' => 4, 'd' => 'carousel', 'm' => 'carousel', 'cd' => 4, 'cm' => 1, 'pd' => .5, 'pm' => .5, 'arr' => true, 'arrm' => true],
        'd' => ['n' => 4, 'd' => 'grid', 'm' => 'carousel', 'cd' => 5, 'cm' => 2, 'dn' => 5, 'pm' => .3, 'arr' => false],
        'e' => ['n' => 3, 'd' => 'carousel', 'm' => 'carousel', 'cd' => 5, 'cm' => 2, 'pd' => .3, 'pm' => .3, 'arr' => true],
    ][$v];
    $tabs = array_slice($tabs, 0, $cfg['n']);
    $skin = \App\Support\GridSkins::resolve(null);
    $sty = '--gs-d:'.$cfg['cd'].';--gs-m:'.$cfg['cm'].(isset($cfg['pd']) && $cfg['d'] === 'carousel' ? ';--gs-peek-d:'.$cfg['pd'] : '').(isset($cfg['pm']) && $cfg['m'] === 'carousel' ? ';--gs-peek-m:'.$cfg['pm'] : '');
    $cls = 'kbb-pgrid gs-grid'.($cfg['d'] === 'carousel' ? ' gs-car-d' : '').($cfg['m'] === 'carousel' ? ' gs-car-m' : '');
    $eyebrow = $ar ? 'تسوّقي حسب المزاج' : 'Shop the edit';
    $title = $ar ? 'المفضّلة لدى عميلاتنا' : 'Loved by our customers';
    $va = __('store.product_grid.view_all');
    $prevSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg>';
    $nextSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg>';
@endphp
@section('content')
<div class="kbb-home">
<div class="wrap"><span class="htp-badge">PREVIEW {{ $names[$v] }}</span></div>
<section class="sec hs kbb-gsec htp htp-{{ $v }}" data-rp-tabs aria-labelledby="htp-h-{{ $v }}"><div class="wrap">
@php
    $tablist = '<div class="htp-tl" role="tablist" aria-label="'.e($title).'">';
    foreach ($tabs as $i => $t) {
        $tablist .= '<button type="button" class="htp-t" role="tab" id="htp-t-'.$v.'-'.$t['key'].'" aria-controls="htp-p-'.$v.'-'.$t['key'].'" aria-selected="'.($i === 0 ? 'true' : 'false').'"'.($i > 0 ? ' tabindex="-1"' : '').' data-rp-tab>'.e($t['title']).'</button>';
    }
    $tablist .= '</div>';
@endphp
@if ($v === 'd')
  <div class="htp-hd"><div><span class="hs-eyebrow">{{ $eyebrow }}</span><h2 id="htp-h-{{ $v }}">{{ $title }}</h2></div>{!! $tablist !!}</div>
@elseif ($v === 'e')
  <span class="hs-eyebrow" id="htp-h-{{ $v }}">{{ $eyebrow }}</span>
  {!! $tablist !!}
@else
  <div class="hs-head"><div class="hs-ht"><span class="hs-eyebrow">{{ $eyebrow }}</span><h2 id="htp-h-{{ $v }}">{{ $title }}</h2></div></div>
  {!! $tablist !!}
@endif
@foreach ($tabs as $i => $t)
  <div class="htp-panel" id="htp-p-{{ $v }}-{{ $t['key'] }}" role="tabpanel" aria-labelledby="htp-t-{{ $v }}-{{ $t['key'] }}"@if ($cfg['arr']) data-ymal @endif @if ($i > 0) hidden @endif>
    @if ($cfg['arr'])<div class="gs-stage{{ empty($cfg['arrm']) ? ' gs-noarr-m' : '' }}"><button type="button" class="gs-arr gs-prev" data-ymal-prev aria-label="{{ __('store.product.related_prev') }}" disabled>{!! $prevSvg !!}</button>@endif
    <div class="{{ $cls }}" data-skin="{{ $skin }}" style="{{ $sty }}"@if ($cfg['arr']) data-ymal-track @endif>
      @foreach ($t['items'] as $j => $p)
        <div class="gs-cell{{ isset($cfg['dn']) && $j >= $cfg['dn'] ? ' gs-m-only' : '' }}{{ isset($cfg['mn']) && $j >= $cfg['mn'] ? ' gs-d-only' : '' }}"><x-product-card :product="$p" /></div>
      @endforeach
    </div>
    @if ($cfg['arr'])<button type="button" class="gs-arr gs-next" data-ymal-next aria-label="{{ __('store.product.related_next') }}">{!! $nextSvg !!}</button></div>@endif
    <div class="gs-foot"><a class="gs-all" href="{{ $t['href'] }}">{{ $va }} · {{ $t['title'] }}</a></div>
  </div>
@endforeach
</div></section>
</div>
@endsection
