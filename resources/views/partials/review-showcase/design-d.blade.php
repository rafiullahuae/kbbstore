{{-- Lane RD · design D · PHOTO REVIEWS CAROUSEL (preview only, not wired).
     Customers' own photographs, portrait, with the stars and a line of the
     review over a soft shade and the product underneath. Scroll-snap, real
     arrow and dot buttons. Every photograph offers its img-cache copies. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $cards = array_slice($rs['photos'], 0, 10);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
@endphp
@if (count($cards) >= 3)
<style>
.rs-d-hd{display:flex;align-items:flex-end;justify-content:space-between;gap:12px 20px;flex-wrap:wrap;margin-bottom:18px}
.rs-d h2{margin:0;font-size:clamp(22px,2.4vw,30px);font-weight:700;line-height:1.15}
.rs-d-sc{margin:8px 0 0;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--rs-mut)}
.rs-d-sc b{color:var(--rs-ink);font-size:15px}
.rs-d-ctl{display:flex;align-items:center;gap:8px}
.rs-d-ctl .rs-all{margin-inline-end:10px}
.rs-d-trk{gap:14px}
.rs-d-c{width:calc((100% - 42px) / 4.35);margin:0}
.rs-d-f{position:relative;aspect-ratio:4/5;border-radius:18px;overflow:hidden;background:#F3ECEF}
.rs-d-f img{width:100%;height:100%;object-fit:cover;display:block}
.rs-d-f figcaption{position:absolute;inset:auto 0 0;padding:44px 16px 14px;background:linear-gradient(180deg,transparent,rgba(30,20,26,.72));color:#fff;display:flex;flex-direction:column;gap:6px}
.rs-d-f .rs-st{font-size:13px;color:rgba(255,255,255,.45)}
.rs-d-f q{font-size:14px;line-height:1.4;font-weight:500;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;quotes:"\201C" "\201D"}
.rs-d-f .rs-who{color:rgba(255,255,255,.85);font-size:12px}
.rs-d-f .rs-who b{color:#fff}
.rs-d-f .rs-vf{color:#BFF0D4}
.rs-d-c>.rs-pr{margin-top:12px}
.rs-d .rs-dots{margin-top:16px}
@media (max-width:1023px){.rs-d-c{width:calc((100% - 28px) / 2.6)}}
@media (max-width:639px){.rs-d-c{width:68%}.rs-d-ctl .rs-btn{display:none}.rs-d-ctl .rs-all{margin:0}.rs-d-hd{flex-direction:column;align-items:flex-start}}
</style>
<section class="sec rsx rs-d" data-rs="car" aria-labelledby="rs-d-h"><div class="wrap">
  <div class="rs-d-hd">
    <div>
      <p class="rs-eb">{{ $S::w('eyebrow') }}</p>
      <h2 id="rs-d-h">{{ $S::w('loved') }}</h2>
      @if ($sum['score'])<p class="rs-d-sc">@include('partials.review-showcase._stars', ['v' => $sum['average']])<b>{{ $avg }}</b><span>{{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</span></p>@endif
    </div>
    <div class="rs-d-ctl">
      <a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a>
      <button class="rs-btn" type="button" data-rs-go="prev" aria-label="{{ $S::w('prev') }}">@include('partials.review-showcase._arrow', ['back' => true])</button>
      <button class="rs-btn" type="button" data-rs-go="next" aria-label="{{ $S::w('next') }}">@include('partials.review-showcase._arrow')</button>
    </div>
  </div>
  <div class="rs-trk rs-d-trk" data-rs-track tabindex="0" role="region" aria-label="{{ $S::w('region') }}">
    @foreach ($cards as $i => $c)
    <article class="rs-d-c" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ count($cards) }}">
      <figure class="rs-d-f" style="margin:0">
        <img src="{{ $c['photo']['src'] }}" @if ($c['photo']['srcset'] !== '') srcset="{{ $c['photo']['srcset'] }}" sizes="(max-width:639px) 68vw, (max-width:1023px) 38vw, 300px" @endif width="400" height="500" alt="" loading="lazy" decoding="async">
        <figcaption>
          @include('partials.review-showcase._stars', ['v' => $c['rating']])
          <q dir="auto">{{ $c['title'] !== '' ? $c['title'] : $c['text'] }}</q>
          @include('partials.review-showcase._who', ['date' => false])
        </figcaption>
      </figure>
      @include('partials.review-showcase._product')
    </article>
    @endforeach
  </div>
  <div class="rs-dots">@foreach ($cards as $i => $c)<button type="button" data-rs-dot data-rs-go="{{ $i }}" aria-label="{{ $S::w('go', ['n' => $i + 1]) }}" aria-current="{{ $i === 0 ? 'true' : 'false' }}"></button>@endforeach</div>
</div></section>
@endif
