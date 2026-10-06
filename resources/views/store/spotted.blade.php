@extends('layouts.store')
{{--
    /kbeautybliss-spotted/ — the owner's hand-picked Instagram grid.  (Lane HB)

    Master plan row 55, item 4: "a button to a new page /kbeautybliss-spotted
    with a manually-selected Instagram grid", and the SEO list for the same row:
    one H1, crawlable <a href> on every card, alt and width/height on every
    picture, BreadcrumbList JSON-LD. The controller builds the title, the
    description, the canonical and the breadcrumb (App\Support\Seo renders
    them); this view draws the H1, the introduction and the grid of polaroid
    cards — the same partial the homepage carousel uses.

    Controls: Appearance → #KBeautyBliss Spotted → Spotted page.
--}}
@section('title', $seoTitle)

@if ($ig['cards'] !== [])
{{-- (Lane SG) The Instagram cards' styles: this page only, inline, so no other
     page of the shop gains a byte and no stylesheet request is added. Every
     size is a calc(), an aspect-ratio or a clamp(); logical properties, so
     Arabic mirrors. The palette is the shop's own (--pink, --blush …). --}}
@push('styles')
<style>
/* Shared by all four styles. */
.kbb-home .sig-grid{display:grid;grid-template-columns:repeat(var(--sig-cols-d,4),minmax(0,1fr));gap:24px 20px;list-style:none;margin:0;padding:6px 0 12px}
.kbb-home .sig-cell{list-style:none;min-width:0}
.kbb-home .sig-card{position:relative;display:flex;flex-direction:column;height:100%;background:#fff;border-radius:18px;overflow:hidden;color:var(--ink);text-decoration:none;
  border:1px solid var(--blush);box-shadow:0 18px 36px -28px rgba(120,40,80,.55),0 1px 2px rgba(42,34,40,.05);transition:transform .25s var(--ease),box-shadow .25s var(--ease)}
.kbb-home .sig-card:hover{transform:translateY(-4px);box-shadow:0 26px 44px -26px rgba(120,40,80,.6)}
.kbb-home .sig-card:focus-visible{outline:2px solid var(--pink-deep);outline-offset:3px}
.kbb-home .sig-ph{position:relative;display:block;aspect-ratio:4/5;background:var(--pink-soft);overflow:hidden}
.kbb-home .sig-ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;transition:transform .5s var(--ease)}
.kbb-home .sig-card:hover .sig-ph img{transform:scale(1.035)}
.kbb-home .sig-badge{position:absolute;top:10px;inset-inline-end:10px;z-index:1;display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:99px;
  background:rgba(30,16,24,.55);color:#fff;font-size:12px;font-weight:700;line-height:1}
.kbb-home .sig-badge svg{width:15px;height:15px;flex:none}
.kbb-home .sig-play{position:absolute;inset-inline-start:calc(50% - 28px);top:calc(50% - 28px);width:56px;height:56px;border-radius:50%;display:grid;place-items:center;
  background:rgba(255,255,255,.88);color:var(--pink);box-shadow:0 10px 26px -10px rgba(0,0,0,.45);transition:transform .25s var(--ease),background .25s}
.kbb-home .sig-play svg{width:24px;height:24px;margin-inline-start:3px}
.kbb-home .sig-card:hover .sig-play{transform:scale(1.08);background:#fff}
.kbb-home .sig-body{display:flex;flex-direction:column;gap:8px;padding:12px 14px 14px;flex:1;min-width:0}
.kbb-home .sig-prof{display:flex;align-items:center;gap:8px;min-width:0;font-size:13px;line-height:1.2}
.kbb-home .sig-prof b{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700}
.kbb-home .sig-av{flex:none;width:28px;height:28px;border-radius:50%;padding:2px;background:linear-gradient(135deg,#F58529,#DD2A7B,#8134AF)}
.kbb-home .sig-av img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;border:2px solid #fff}
.kbb-home .sig-ig{flex:none;width:16px;height:16px;margin-inline-start:auto;color:var(--pink-deep)}
.kbb-home .sig-cap{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;overflow:hidden;font-size:13.5px;line-height:1.45;min-height:calc(2 * 1.45em);color:var(--ink-2);overflow-wrap:anywhere}
.kbb-home .sig-date{display:none;font-size:11.5px;color:var(--muted);letter-spacing:.02em}
.kbb-home .sig-stats{display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px;margin-top:auto;padding-top:9px;border-top:1px solid var(--line-2);min-height:calc(18px + 9px);font-size:13px;font-weight:700;color:var(--ink)}
.kbb-home .sig-st{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.kbb-home .sig-st svg{width:17px;height:17px;flex:none;color:var(--pink)}
.kbb-home .sig-sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}

/* A — Instagram native: profile row, square picture, counts, caption, date. */
.kbb-home .sig-a .sig-body{display:contents}
.kbb-home .sig-a .sig-prof{order:-1;padding:10px 12px}
.kbb-home .sig-a .sig-ph{aspect-ratio:1/1}
.kbb-home .sig-a .sig-stats{order:1;margin:0;padding:11px 12px 5px;border:0;min-height:calc(18px + 16px)}
.kbb-home .sig-a .sig-st svg{color:var(--ink)}
.kbb-home .sig-a .sig-st:first-child svg{color:var(--pink)}
.kbb-home .sig-a .sig-cap{order:2;margin:0 12px;color:var(--ink)}
.kbb-home .sig-a .sig-date{order:3;display:block;padding:6px 12px 12px;margin-top:auto;text-transform:uppercase;font-size:10.5px}

/* B — Overlay: the picture is the card; caption and counts over a gradient. */
.kbb-home .sig-b .sig-card{aspect-ratio:4/5;height:auto;border:0;border-radius:16px}
.kbb-home .sig-b .sig-ph{position:absolute;inset:0;aspect-ratio:auto}
.kbb-home .sig-b .sig-body{position:absolute;inset:0;justify-content:flex-end;gap:6px;padding:12px;color:#fff;
  background:linear-gradient(0deg,rgba(24,10,18,.86) 0%,rgba(24,10,18,.45) 34%,rgba(24,10,18,0) 58%)}
.kbb-home .sig-b .sig-prof{position:absolute;top:10px;inset-inline-start:10px;max-width:calc(100% - 70px);padding:3px 10px 3px 3px;border-radius:99px;background:rgba(24,10,18,.5);font-size:12px}
.kbb-home .sig-b .sig-av{width:24px;height:24px}
.kbb-home .sig-b .sig-ig{display:none}
.kbb-home .sig-b .sig-cap{color:#fff;min-height:0;text-shadow:0 1px 8px rgba(0,0,0,.35)}
.kbb-home .sig-b .sig-stats{margin:0;border:0;padding:0;min-height:18px;color:#fff}
.kbb-home .sig-b .sig-st svg{color:#fff}

/* C — Soft pink frame: the picture framed on the shop's blush, counts as pills. */
.kbb-home .sig-c .sig-card{background:var(--pink-soft);padding:9px;border-color:var(--blush)}
.kbb-home .sig-c .sig-ph{border-radius:12px;box-shadow:0 10px 22px -16px rgba(120,40,80,.6)}
.kbb-home .sig-c .sig-body{padding:10px 3px 2px}
.kbb-home .sig-c .sig-cap{color:var(--ink)}
.kbb-home .sig-c .sig-date{display:block}
.kbb-home .sig-c .sig-stats{border:0;padding-top:2px;gap:6px}
.kbb-home .sig-c .sig-st{padding:4px 9px;border-radius:99px;background:#fff;border:1px solid var(--blush);color:var(--pink-deep);font-size:12px}
.kbb-home .sig-c .sig-st svg{width:14px;height:14px}

/* D — Reel-first: tall tiles, big play button, counts down the side. */
.kbb-home .sig-d .sig-card{aspect-ratio:9/16;height:auto;border:0;border-radius:16px;background:#1d1218}
.kbb-home .sig-d .sig-ph{position:absolute;inset:0;aspect-ratio:auto}
.kbb-home .sig-d .sig-play{width:68px;height:68px;inset-inline-start:calc(50% - 34px);top:calc(50% - 34px)}
.kbb-home .sig-d .sig-play svg{width:30px;height:30px}
.kbb-home .sig-d .sig-body{position:absolute;inset:0;justify-content:flex-end;gap:6px;padding:12px;padding-inline-end:58px;color:#fff;
  background:linear-gradient(0deg,rgba(14,6,10,.82) 0%,rgba(14,6,10,.3) 32%,rgba(14,6,10,0) 50%)}
.kbb-home .sig-d .sig-prof{font-size:12.5px}
.kbb-home .sig-d .sig-ig{display:none}
.kbb-home .sig-d .sig-cap{color:#fff;min-height:0}
.kbb-home .sig-d .sig-stats{position:absolute;inset-inline-end:8px;bottom:14px;flex-direction:column;flex-wrap:nowrap;gap:14px;margin:0;padding:0;border:0;min-height:0;color:#fff;font-size:12px}
.kbb-home .sig-d .sig-st{flex-direction:column;gap:3px;text-shadow:0 1px 6px rgba(0,0,0,.5)}
.kbb-home .sig-d .sig-st svg{width:26px;height:26px;color:#fff;filter:drop-shadow(0 1px 4px rgba(0,0,0,.45))}

@media (max-width:900px){
  .kbb-home .sig-grid{grid-template-columns:repeat(var(--sig-cols-m,2),minmax(0,1fr));gap:14px 10px}
  .kbb-home .sig-card{border-radius:14px}
  .kbb-home .sig-body{padding:9px 10px 11px;gap:6px}
  .kbb-home .sig-prof{font-size:12px;gap:6px}
  .kbb-home .sig-av{width:24px;height:24px}
  .kbb-home .sig-ig{width:14px;height:14px}
  .kbb-home .sig-cap{font-size:12.5px}
  .kbb-home .sig-stats{gap:4px 11px;font-size:12px;padding-top:7px;min-height:calc(15px + 7px)}
  .kbb-home .sig-st{gap:4px}
  .kbb-home .sig-st svg{width:15px;height:15px}
  .kbb-home .sig-play{width:44px;height:44px;inset-inline-start:calc(50% - 22px);top:calc(50% - 22px)}
  .kbb-home .sig-play svg{width:19px;height:19px}
  .kbb-home .sig-badge{top:8px;inset-inline-end:8px;padding:4px 7px;font-size:11px}
  .kbb-home .sig-badge svg{width:13px;height:13px}
  .kbb-home .sig-a .sig-prof{padding:8px 9px}
  .kbb-home .sig-a .sig-stats{padding:8px 9px 4px;min-height:calc(15px + 12px)}
  .kbb-home .sig-a .sig-cap{margin:0 9px}
  .kbb-home .sig-a .sig-date{padding:5px 9px 10px}
  .kbb-home .sig-b .sig-body{padding:9px}
  .kbb-home .sig-b .sig-prof{top:8px;inset-inline-start:8px;font-size:11px;max-width:calc(100% - 56px)}
  .kbb-home .sig-b .sig-av{width:20px;height:20px}
  .kbb-home .sig-c .sig-card{padding:7px}
  .kbb-home .sig-c .sig-body{padding:8px 2px 2px}
  .kbb-home .sig-c .sig-stats{gap:4px}
  .kbb-home .sig-c .sig-st{gap:3px;padding:2px 6px;border:0;font-size:11px}
  .kbb-home .sig-c .sig-st svg{width:11px;height:11px}
  .kbb-home .sig-d .sig-body{padding:9px;padding-inline-end:44px}
  .kbb-home .sig-d .sig-play{width:52px;height:52px;inset-inline-start:calc(50% - 26px);top:calc(50% - 26px)}
  .kbb-home .sig-d .sig-play svg{width:23px;height:23px}
  .kbb-home .sig-d .sig-stats{inset-inline-end:5px;bottom:10px;gap:10px;font-size:11px}
  .kbb-home .sig-d .sig-st svg{width:21px;height:21px}
}
.sigm{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;padding:12px}
.sigm[hidden]{display:none}
.sigm-back{position:absolute;inset:0;background:rgba(24,12,18,.78)}
.sigm-box{position:relative;display:flex;flex-direction:column;align-items:center;gap:10px;width:min(100%,calc((100dvh - 120px) * .5625 + 2px),402px)}
.sigm-frame{width:100%;aspect-ratio:400/712;background:#fff;border-radius:14px;overflow:hidden}
.sigm-frame iframe{display:block;width:100%;height:100%;border:0}
.sigm-x{position:absolute;top:-6px;inset-inline-end:-6px;transform:translateY(-100%);width:40px;height:40px;border-radius:50%;border:0;display:grid;place-items:center;background:#fff;color:var(--ink);cursor:pointer;box-shadow:0 6px 18px -8px rgba(0,0,0,.5)}
.sigm-x svg{width:18px;height:18px}
.sigm-x:focus-visible,.sigm-ig:focus-visible{outline:2px solid #fff;outline-offset:3px}
.sigm-ig{color:#fff;font-size:13.5px;font-weight:700;text-decoration:underline;text-underline-offset:3px}
html.sigm-on{overflow:hidden}
@media (prefers-reduced-motion:reduce){.kbb-home .sig-card,.kbb-home .sig-ph img,.kbb-home .sig-play{transition:none}}
</style>
@endpush
@endif

@section('content')
<div class="kbb-home">
<section class="sec spt spt-lilac spt-page-sec"><div class="wrap">
  <nav class="crumb spt-crumb" aria-label="{{ __('store.spotted.breadcrumb_label') }}"><a href="{{ \App\Support\Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <span>{{ $h1 }}</span></nav>
  <div class="sh spt-head"><div><h1>{{ $h1 }}</h1>
    <p>{{ $intro }}</p></div></div>
@if ($ig['cards'] !== [])
@php $sigSizes = \App\Services\SpottedInstagram::sizes($page['cols_d'], $page['cols_m'], $page['card']); @endphp
  <ul class="sig-grid sig-{{ $page['card'] }}" style="--sig-cols-d:{{ $page['cols_d'] }};--sig-cols-m:{{ $page['cols_m'] }}">
@foreach ($ig['cards'] as $i => $card)
    <li class="sig-cell">@include('partials.spotted-ig-card', ['card' => $card, 'profile' => $ig['profile'], 'lazy' => $i >= 4, 'play' => $page['play'], 'sizes' => $sigSizes])</li>
@endforeach
  </ul>
@elseif ($cards === [])
  <p class="spt-empty">{{ __('store.spotted.empty') }}</p>
@else
  <ul class="spt-grid {{ $page['classes'] }}" style="{{ $page['style'] }}">
@foreach ($cards as $i => $card)
    <li class="spt-cell">@include('partials.spotted-card', ['card' => $card, 'lazy' => $i >= 4])</li>
@endforeach
  </ul>
@endif
</div></section>
</div>
@if ($ig['cards'] !== [] && $page['play'] && collect($ig['cards'])->contains(fn ($c) => $c['embed'] !== null))
{{-- (Lane SG) The video player: Instagram's own embed in a dialog, created on
     a tap and REMOVED on close, so a page nobody taps makes no request to
     instagram.com and a closed player stops playing. Measures nothing; every
     size below is a calc()/aspect-ratio. Focus moves in, is held, and goes
     back to the card. textContent/setAttribute only — no markup from strings. --}}
<div class="sigm" id="sigm" hidden role="dialog" aria-modal="true" aria-label="{{ __('store.spotted.video_dialog') }}">
  <div class="sigm-back" data-sigm-close></div>
  <div class="sigm-box">
    <button type="button" class="sigm-x" data-sigm-close aria-label="{{ __('store.spotted.close') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    <div class="sigm-frame" data-sigm-frame></div>
    <a class="sigm-ig" data-sigm-link href="https://www.instagram.com/" target="_blank" rel="noopener">{{ __('store.spotted.view_on_ig') }} <span aria-hidden="true">↗</span></a>
  </div>
</div>
<script>
(function () {
  'use strict';
  var box = document.getElementById('sigm');
  if (!box) return;
  var holder = box.querySelector('[data-sigm-frame]');
  var link = box.querySelector('[data-sigm-link]');
  var closer = box.querySelector('.sigm-x');
  var opener = null;
  var EMBED = /^https:\/\/www\.instagram\.com\/(p|reel)\/[A-Za-z0-9_-]{1,64}\/embed\/$/;

  function open(card) {
    var url = card.getAttribute('data-sig-embed') || '';
    if (!EMBED.test(url)) return false;
    var frame = document.createElement('iframe');
    frame.setAttribute('src', url);
    frame.setAttribute('title', {{ \Illuminate\Support\Js::from(__('store.spotted.video_dialog')) }});
    frame.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
    frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    frame.setAttribute('scrolling', 'no');
    holder.textContent = '';
    holder.appendChild(frame);
    link.setAttribute('href', card.getAttribute('href'));
    opener = card;
    box.hidden = false;
    document.documentElement.classList.add('sigm-on');
    closer.focus();
    return true;
  }

  function shut() {
    if (box.hidden) return;
    box.hidden = true;
    holder.textContent = '';
    document.documentElement.classList.remove('sigm-on');
    if (opener) { opener.focus(); opener = null; }
  }

  document.addEventListener('click', function (e) {
    var card = e.target.closest && e.target.closest('[data-sig-embed]');
    if (card && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey && e.button === 0) {
      if (open(card)) e.preventDefault();
      return;
    }
    if (e.target.closest && e.target.closest('[data-sigm-close]')) shut();
  });

  box.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); shut(); return; }
    if (e.key !== 'Tab') return;
    var stops = [closer, holder.firstChild, link].filter(Boolean);
    var i = stops.indexOf(document.activeElement);
    if (e.shiftKey && i <= 0) { e.preventDefault(); stops[stops.length - 1].focus(); }
    else if (!e.shiftKey && i === stops.length - 1) { e.preventDefault(); stops[0].focus(); }
  });
})();
</script>
@endif
@endsection
