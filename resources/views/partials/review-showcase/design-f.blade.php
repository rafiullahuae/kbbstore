{{-- Lane RD · design F · MASONRY WALL (preview only, not wired).
     Reviews at their natural lengths in CSS columns -- four on a laptop, two
     on a tablet, one on a phone -- so short and long ones pack without a
     script. Shoppers' photographs head the cards that have one. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $cards = array_slice($rs['items'], 0, 8);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
@endphp
<style>
.rs-f-hd{text-align:center;margin:0 auto 24px;max-width:640px}
.rs-f h2{margin:0;font-size:clamp(24px,2.8vw,34px);font-weight:700;line-height:1.15;letter-spacing:-.01em}
.rs-f-sc{margin:10px 0 0;display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--rs-mut)}
.rs-f-sc b{color:var(--rs-ink);font-size:15px}
.rs-f-sc .rs-st{font-size:15px}
.rs-f-w{columns:4 240px;column-gap:16px}
.rs-f-k{break-inside:avoid;display:flex;flex-direction:column;gap:11px;margin:0 0 16px;padding:20px;border:1px solid var(--rs-line);border-radius:18px;background:#fff}
.rs-f-k:nth-child(3n+2){background:var(--rs-cream);border-color:transparent}
.rs-f-k>img{width:calc(100% + 40px);max-width:none;height:auto;aspect-ratio:4/5;object-fit:cover;margin:-20px -20px 4px;border-radius:18px 18px 0 0;display:block;background:#F3ECEF}
.rs-f-k .rs-st{font-size:13px}
.rs-f-k h3{margin:0;font-size:15px;font-weight:600;line-height:1.35}
.rs-f-k p{margin:0;font-size:13.5px;line-height:1.6;color:var(--ink-2,#5E545A)}
.rs-f-k .rs-pr{padding-top:11px;border-top:1px solid var(--rs-line)}
.rs-f-k:nth-child(3n+2) .rs-pr{border-color:rgba(42,34,40,.08)}
.rs-f-ft{text-align:center;margin-top:8px}
.rs-f-ft a{display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border-radius:99px;background:var(--rs-ink);color:#fff;font-weight:600;font-size:14px}
.rs-f-ft a:hover{background:var(--rs-pink)}
.rs-f-ft svg{width:16px;height:16px}
[dir=rtl] .rs-f-ft svg{transform:scaleX(-1)}
@media (max-width:639px){.rs-f-w{columns:1}.rs-f-k:nth-child(n+5){display:none}.rs-f-k>img{aspect-ratio:16/11}}
</style>
<section class="sec rsx rs-f" aria-labelledby="rs-f-h"><div class="wrap">
  <div class="rs-f-hd">
    <p class="rs-eb">{{ $S::w('eyebrow') }}</p>
    <h2 id="rs-f-h">{{ __('store.home.reviews_heading') }}</h2>
    @if ($sum['score'])<p class="rs-f-sc">@include('partials.review-showcase._stars', ['v' => $sum['average']])<b>{{ $avg }}</b><span>· {{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</span></p>@endif
  </div>
  <div class="rs-f-w">
    @foreach ($cards as $c)
    <article class="rs-f-k">
      @if ($c['photo'])<img src="{{ $c['photo']['src'] }}" @if ($c['photo']['srcset'] !== '') srcset="{{ $c['photo']['srcset'] }}" sizes="(max-width:639px) 92vw, 300px" @endif width="400" height="500" alt="" loading="lazy" decoding="async">@endif
      @include('partials.review-showcase._stars', ['v' => $c['rating']])
      @if ($c['title'] !== '')<h3 dir="auto">{{ $c['title'] }}</h3>@endif
      <p dir="auto">{{ $c['text'] }}</p>
      @include('partials.review-showcase._who')
      @include('partials.review-showcase._product')
    </article>
    @endforeach
  </div>
  <div class="rs-f-ft"><a href="{{ $rs['all'] }}">{{ $S::w('see_all') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></a></div>
</div></section>
