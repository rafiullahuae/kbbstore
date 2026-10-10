{{-- Lane RD · design G · SPLIT HERO QUOTE (preview only, not wired).
     One review, large, beside the product it is about -- an editorial
     moment rather than a list -- with the shop-wide score and the next few
     reviewers underneath. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $hero = collect($rs['items'])->first(fn ($c) => $c['product'] && $c['product']['img'] && $c['rating'] === 5 && mb_strlen($c['text']) <= 200) ?? ($rs['items'][0] ?? null);
    $more = array_slice(array_values(array_filter($rs['items'], fn ($c) => $hero === null || $c['id'] !== $hero['id'])), 0, 4);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
    $rest = max(0, (int) $sum['total'] - 1 - count($more));
@endphp
@if ($hero)
<style>
.rs-g-g{display:grid;grid-template-columns:1.15fr .85fr;gap:clamp(24px,5vw,64px);align-items:center}
.rs-g-q{display:flex;flex-direction:column;gap:18px;min-width:0}
.rs-g-q h2{margin:0}
.rs-g-q blockquote{margin:0;position:relative;font-size:clamp(21px,2.6vw,34px);line-height:1.38;font-weight:400;letter-spacing:-.01em}
.rs-g-q blockquote::before{content:"\201C";display:block;font:700 72px/.7 Georgia,serif;color:var(--rs-pink);margin-bottom:10px}
.rs-g-q .rs-st{font-size:17px}
.rs-g-by{display:flex;align-items:center;gap:12px}
.rs-g-by .rs-av{width:42px;height:42px;font-size:15px}
.rs-g-ft{display:flex;align-items:center;flex-wrap:wrap;gap:12px 18px;padding-top:20px;margin-top:4px;border-top:1px solid var(--rs-line);font-size:13px;color:var(--rs-mut)}
.rs-g-ft b{color:var(--rs-ink);font-size:15px}
.rs-g-av{display:flex}
.rs-g-av span{margin-inline-start:-8px;border:2px solid #fff;width:32px;height:32px;font-size:12px}
.rs-g-av span:first-child{margin-inline-start:0}
.rs-g-av span.n{background:var(--rs-ink);color:#fff;font-size:10.5px}
.rs-g-ft .rs-all{margin-inline-start:auto}
.rs-g-p{display:block;background:var(--rs-cream);border-radius:24px;padding:clamp(16px,2.4vw,28px);text-align:center}
.rs-g-p img{width:100%;height:auto;aspect-ratio:1;object-fit:cover;border-radius:16px;display:block;background:#fff}
.rs-g-p em{display:block;margin:16px 0 4px;font-style:normal;font-weight:600;font-size:15px;line-height:1.35}
.rs-g-p small{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--rs-pink)}
.rs-g-p small svg{width:15px;height:15px}
[dir=rtl] .rs-g-p small svg{transform:scaleX(-1)}
.rs-g-p:hover em{color:var(--rs-pink)}
@media (max-width:767px){.rs-g-g{grid-template-columns:1fr}.rs-g-p{display:grid;grid-template-columns:96px 1fr;gap:14px;align-items:center;text-align:start;padding:12px;border-radius:18px}.rs-g-p em{margin:0 0 4px;font-size:14px}.rs-g-p img{border-radius:12px}.rs-g-ft .rs-all{margin-inline-start:0}}
</style>
<section class="sec rsx rs-g" aria-labelledby="rs-g-h"><div class="wrap"><div class="rs-g-g">
  <figure class="rs-g-q" style="margin:0">
    <h2 class="rs-eb" id="rs-g-h">{{ $S::w('eyebrow') }}</h2>
    @include('partials.review-showcase._stars', ['v' => $hero['rating']])
    <blockquote dir="auto">{{ $hero['text'] }}</blockquote>
    <figcaption class="rs-g-by"><span class="rs-av">{{ $hero['initial'] }}</span>@include('partials.review-showcase._who', ['c' => $hero])</figcaption>
    <div class="rs-g-ft">
      <span class="rs-g-av" aria-hidden="true">@foreach ($more as $m)<span class="rs-av">{{ $m['initial'] }}</span>@endforeach @if ($rest)<span class="rs-av n">+{{ $rest }}</span>@endif</span>
      @if ($sum['score'])<span><b>{{ $avg }}</b> ★ · {{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</span>@endif
      <a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a>
    </div>
  </figure>
  @if ($hero['product'])
  <a class="rs-g-p" href="{{ $hero['product']['url'] }}">
    @if ($hero['product']['img'])<img src="{{ $hero['product']['img'] }}" @if ($hero['product']['srcset'] !== '') srcset="{{ $hero['product']['srcset'] }}" sizes="(max-width:767px) 96px, 420px" @endif width="420" height="420" alt="{{ $hero['product']['name'] }}" loading="lazy" decoding="async">@endif
    <span><em dir="auto">{{ $hero['product']['name'] }}</em><small>{{ $S::w('shop') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></small></span>
  </a>
  @endif
</div></div></section>
@endif
