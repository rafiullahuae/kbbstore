{{-- Lane RD · design H · COMPACT TRUST STRIP (preview only, not wired).
     One slim band: the score, the newest five-star line with its product, and
     the way to the rest. Built to sit under a banner or above the footer
     without taking a screen. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $q = collect($rs['items'])->first(fn ($c) => $c['rating'] === 5 && $c['product']) ?? ($rs['items'][0] ?? null);
    $faces = array_slice($rs['items'], 0, 3);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
@endphp
@if ($q)
<style>
.rs-h-b{display:flex;align-items:center;gap:14px 22px;padding:14px 20px;border:1px solid var(--rs-line);border-radius:18px;background:#fff}
.rs-h-sc{display:flex;align-items:center;gap:12px;flex:none}
.rs-h-sc strong{font-size:30px;font-weight:700;line-height:1}
.rs-h-sc h2{margin:0;font-size:12.5px;font-weight:500;color:var(--rs-mut);line-height:1.45}
.rs-h-sc .rs-st{font-size:14px;display:block;width:max-content}
.rs-h-fc{display:flex}
.rs-h-fc .rs-av{width:28px;height:28px;font-size:11px;border:2px solid #fff;margin-inline-start:-7px}
.rs-h-fc .rs-av:first-child{margin-inline-start:0}
.rs-h-q{display:flex;align-items:center;gap:12px;min-width:0;flex:1;padding-inline-start:22px;border-inline-start:1px solid var(--rs-line)}
.rs-h-q img,.rs-h-q .rs-ph0{width:40px;height:40px;border-radius:10px;object-fit:cover;background:#F6F1F3;flex:none}
.rs-h-q>span{min-width:0}
.rs-h-q q{display:block;font-size:13.5px;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-h-q small{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--rs-mut);margin-top:2px;white-space:nowrap;overflow:hidden}
.rs-h-q small b{color:var(--rs-ink);font-weight:600}
.rs-h-pn{min-width:0;overflow:hidden;text-overflow:ellipsis}
.rs-h-b>.rs-all{flex:none}
@media (max-width:767px){.rs-h-b{flex-wrap:wrap;padding:14px 16px}.rs-h-q{order:3;flex-basis:100%;padding:12px 0 0;border:0;border-top:1px solid var(--rs-line)}.rs-h-q q{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}.rs-h-b>.rs-all{margin-inline-start:auto}.rs-h-fc{display:none}}
</style>
<section class="sec rsx rs-h" aria-labelledby="rs-h-h"><div class="wrap"><div class="rs-h-b">
  <div class="rs-h-sc">
    @if ($sum['score'])<strong>{{ $avg }}</strong>@endif
    <div>@if ($sum['score'])@include('partials.review-showcase._stars', ['v' => $sum['average']])@endif<h2 id="rs-h-h">{{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</h2></div>
    <span class="rs-h-fc" aria-hidden="true">@foreach ($faces as $f)<span class="rs-av">{{ $f['initial'] }}</span>@endforeach</span>
  </div>
  <a class="rs-h-q" href="{{ $q['product'] ? $q['product']['url'] : $rs['all'] }}">
    @if ($q['product'] && $q['product']['img'])<img src="{{ $q['product']['img'] }}" @if ($q['product']['srcset'] !== '') srcset="{{ $q['product']['srcset'] }}" sizes="40px" @endif width="40" height="40" alt="{{ $q['product']['name'] }}" loading="lazy" decoding="async">@else<span class="rs-ph0"></span>@endif
    <span><q dir="auto">{{ $q['text'] }}</q><small>@include('partials.review-showcase._stars', ['v' => $q['rating']])<b dir="auto">{{ $q['name'] }}</b>@if ($q['city'] !== '')<span>{{ $q['city'] }}</span>@endif @if ($q['verified'])@include('partials.review-showcase._vf')@endif @if ($q['product'])<span class="rs-h-pn" dir="auto">· {{ $q['product']['name'] }}</span>@endif</small></span>
  </a>
  <a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a>
</div></div></section>
@endif
