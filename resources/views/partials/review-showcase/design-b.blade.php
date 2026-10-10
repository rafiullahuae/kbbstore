{{-- Lane RD · design B · SPOTLIGHT QUOTE (preview only, not wired).
     One large quote at a time on the shop's cream, cross-fading. It advances
     when the active dot's progress line finishes (6s, CSS), pauses while the
     pointer is over it, while anything in it has focus, while it is off-screen
     and when Pause is pressed; stops for good after two rounds or the first
     press of an arrow or dot; never moves under prefers-reduced-motion.
     Slides share one grid cell, so the box is the tallest quote's height from
     the first paint and nothing below it shifts. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $cards = array_slice(array_values(array_filter($rs['items'], static fn ($c) => mb_strlen($c['text']) <= 190)), 0, 6);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
@endphp
<style>
.rs-b-in{background:var(--rs-cream);border-radius:24px;padding:clamp(28px,5vw,60px) clamp(18px,5vw,64px) clamp(22px,3vw,34px);text-align:center}
.rs-b-top{display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:6px 12px;font-size:13px;color:var(--rs-mut);margin-bottom:clamp(18px,3vw,30px)}
.rs-b-top h2{margin:0;font-size:11.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--rs-pink)}
.rs-b-top b{color:var(--rs-ink)}
.rs-b-top i.rs-sep{width:3px;height:3px;border-radius:50%;background:currentColor}
.rs-b-stage{display:grid;max-width:820px;margin:0 auto}
.rs-b-s{grid-area:1/1;display:flex;flex-direction:column;align-items:center;gap:16px;opacity:0;visibility:hidden;transition:opacity .6s,visibility 0s .6s}
.rs-b-s.on{opacity:1;visibility:visible;transition:opacity .6s}
.rs-b-s .rs-st{font-size:18px}
.rs-b-s blockquote{margin:0;font-size:clamp(19px,2.5vw,28px);line-height:1.45;font-weight:400;letter-spacing:-.005em;text-wrap:balance}
.rs-b-s .rs-who{justify-content:center}
.rs-b-s .rs-pr{text-align:start;background:#fff;border-radius:14px;padding:8px 14px 8px 8px;padding-inline:8px 14px;box-shadow:0 1px 2px rgba(42,34,40,.05)}
.rs-b-nav{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:clamp(20px,3vw,30px)}
.rs-b .rs-btn{width:38px;height:38px;background:transparent}
.rs-b .rs-dots button{width:34px}
.rs-b .rs-dots button::before{width:22px;height:3px}
.rs-b .rs-dots button[aria-current=true]::before{width:22px;background:#E3D8DD}
.rs-b .rs-dots button{position:relative}
.rs-b [data-rs-bar]{position:absolute;inset-inline-start:6px;top:50%;width:22px;height:3px;margin-top:-1.5px;border-radius:9px;background:var(--rs-ink);transform:scaleX(0);transform-origin:0 50%}
[dir=rtl] .rs-b [data-rs-bar]{transform-origin:100% 50%}
.rs-b [aria-current=true] [data-rs-bar]{animation:rs-b-fill 6s linear forwards}
.rs-b:hover [data-rs-bar],.rs-b:focus-within [data-rs-bar],.rs-b.is-out [data-rs-bar],.rs-b.is-paused [data-rs-bar]{animation-play-state:paused}
.rs-b.is-stopped [aria-current=true] [data-rs-bar]{animation:none;transform:none}
@keyframes rs-b-fill{to{transform:scaleX(1)}}
.rs-b-pp{margin-inline-start:6px}
.rs-b.is-stopped .rs-b-pp{visibility:hidden}
.rs-b-all{margin-top:18px}
@media (prefers-reduced-motion:reduce){.rs-b-s{transition:none}.rs-b [data-rs-bar]{animation:none!important;transform:none}.rs-b-pp{display:none}}
@media (max-width:639px){.rs-b .rs-btn{display:none}.rs-b-s .rs-pr em{-webkit-line-clamp:2;white-space:normal}}
</style>
<section class="sec rsx rs-b" data-rs="spot" aria-labelledby="rs-b-h" aria-roledescription="carousel"><div class="wrap"><div class="rs-b-in">
  <div class="rs-b-top">
    <h2 id="rs-b-h">{{ $S::w('eyebrow') }}</h2>
    @if ($sum['score'])<i class="rs-sep"></i>@include('partials.review-showcase._stars', ['v' => $sum['average']])<span><b>{{ $avg }}</b> {{ $S::w('out_of') }}</span><i class="rs-sep"></i><span>{{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</span>@endif
  </div>
  <div class="rs-b-stage" aria-live="off">
    @foreach ($cards as $i => $c)
    <figure class="rs-b-s{{ $i === 0 ? ' on' : '' }}" data-rs-slide aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ count($cards) }}" aria-hidden="{{ $i === 0 ? 'false' : 'true' }}" @if ($i !== 0) inert @endif style="margin:0">
      @include('partials.review-showcase._stars', ['v' => $c['rating']])
      <blockquote dir="auto">“{{ $c['text'] }}”</blockquote>
      <figcaption>@include('partials.review-showcase._who')</figcaption>
      @include('partials.review-showcase._product')
    </figure>
    @endforeach
  </div>
  <div class="rs-b-nav">
    <button class="rs-btn" type="button" data-rs-go="prev" aria-label="{{ $S::w('prev') }}">@include('partials.review-showcase._arrow', ['back' => true])</button>
    <div class="rs-dots">@foreach ($cards as $i => $c)<button type="button" data-rs-dot data-rs-go="{{ $i }}" aria-label="{{ $S::w('go', ['n' => $i + 1]) }}" aria-current="{{ $i === 0 ? 'true' : 'false' }}"><i data-rs-bar></i></button>@endforeach</div>
    <button class="rs-btn" type="button" data-rs-go="next" aria-label="{{ $S::w('next') }}">@include('partials.review-showcase._arrow')</button>
    @include('partials.review-showcase._pp', ['cls' => 'rs-b-pp'])
  </div>
  <div class="rs-b-all"><a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a></div>
</div></div></section>
