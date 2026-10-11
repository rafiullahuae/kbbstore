{{-- Lane RD · design A · CARD CAROUSEL (preview only, not wired).
     A scroll-snap row of review cards: three to a laptop screen, one and a
     peek on a phone. Arrows and dots are real buttons; swipe, trackpad and
     the arrow keys on the focused row scroll it natively. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $cards = array_slice($rs['items'], 0, 9);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
@endphp
<style>
.rs-a-hd{display:flex;align-items:flex-end;justify-content:space-between;gap:14px 20px;flex-wrap:wrap;margin-bottom:18px}
.rs-a h2{margin:0;font-size:clamp(22px,2.4vw,30px);font-weight:700;line-height:1.15}
.rs-a-sc{display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;margin-top:9px;font-size:13px;color:var(--rs-mut)}
.rs-a-sc b{font-size:16px;color:var(--rs-ink)}
.rs-a-sc .rs-st{font-size:15px}
.rs-a-ctl{display:flex;align-items:center;gap:8px}
.rs-a-ctl .rs-all{margin-inline-end:10px}
.rs-a-trk{gap:16px;padding:2px;margin:-2px}
.rs-a-c{width:calc((100% - 32px) / 3);display:flex;flex-direction:column;gap:12px;padding:22px;border:1px solid var(--rs-line);border-radius:18px;background:#fff;position:relative}
.rs-a-c::after{content:"\201C";position:absolute;top:8px;inset-inline-end:18px;font:700 54px/1 Georgia,serif;color:var(--rs-soft)}
.rs-a-c .rs-st{font-size:14px}
.rs-a-c h3{margin:0;font-size:15px;font-weight:600;line-height:1.35}
.rs-a-c p{margin:0;font-size:13.5px;line-height:1.6;color:var(--ink-2,#5E545A);display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.rs-a-c .rs-who{margin-top:auto}
.rs-a-c .rs-pr{padding-top:12px;border-top:1px solid var(--rs-line)}
.rs-a .rs-dots{margin-top:16px}
@media (max-width:1023px){.rs-a-c{width:calc((100% - 16px) / 2.15)}}
@media (max-width:639px){.rs-a-c{width:84%;padding:18px}.rs-a-ctl .rs-btn{display:none}.rs-a-ctl .rs-all{margin:0}.rs-a-hd{align-items:flex-start;flex-direction:column}}
</style>
<section class="sec rsx rs-a" data-rs="car" aria-labelledby="rs-a-h"><div class="wrap">
  <div class="rs-a-hd">
    <div>
      <p class="rs-eb">{{ $S::w('eyebrow') }}</p>
      <h2 id="rs-a-h">{{ __('store.home.reviews_heading') }}</h2>
      @if ($sum['score'])<div class="rs-a-sc">@include('partials.review-showcase._stars', ['v' => $sum['average']])<b>{{ $avg }}</b><span>{{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</span></div>@endif
    </div>
    <div class="rs-a-ctl">
      <a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a>
      <button class="rs-btn" type="button" data-rs-go="prev" aria-label="{{ $S::w('prev') }}">@include('partials.review-showcase._arrow', ['back' => true])</button>
      <button class="rs-btn" type="button" data-rs-go="next" aria-label="{{ $S::w('next') }}">@include('partials.review-showcase._arrow')</button>
    </div>
  </div>
  <div class="rs-trk rs-a-trk" data-rs-track tabindex="0" role="region" aria-label="{{ $S::w('region') }}">
    @foreach ($cards as $i => $c)
    <article class="rs-a-c" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ count($cards) }}">
      @include('partials.review-showcase._stars', ['v' => $c['rating']])
      @if ($c['title'] !== '')<h3 dir="auto">{{ $c['title'] }}</h3>@endif
      <p dir="auto">{{ $c['text'] }}</p>
      @include('partials.review-showcase._who')
      @include('partials.review-showcase._product')
    </article>
    @endforeach
  </div>
  <div class="rs-dots">@foreach ($cards as $i => $c)<button type="button" data-rs-dot data-rs-go="{{ $i }}" aria-label="{{ $S::w('go', ['n' => $i + 1]) }}" aria-current="{{ $i === 0 ? 'true' : 'false' }}"></button>@endforeach</div>
</div></section>
