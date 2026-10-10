{{-- Lane RD · design C · MARQUEE TICKER (preview only, not wired).
     Two slow rows of review pills drifting in opposite directions under a
     compact score. Pure CSS: one transform animation per row on a list that is
     printed twice (the copy is aria-hidden and inert). Paused on hover, on
     focus, off-screen and by its Pause button; under prefers-reduced-motion it
     does not move at all and becomes a row you can swipe. The pills are not
     links, so nothing focusable ever moves under a keyboard user. --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $all = $rs['items'];
    $rows = [array_slice($all, 0, 8), array_slice($all, 8, 8) ?: array_slice($all, 0, 8)];
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
@endphp
<style>
.rs-c-hd{display:flex;align-items:center;justify-content:space-between;gap:12px 20px;flex-wrap:wrap;margin-bottom:18px}
.rs-c-sc{display:flex;align-items:center;gap:14px}
.rs-c-sc strong{font-size:clamp(34px,4vw,44px);font-weight:700;line-height:1}
.rs-c-sc h2{margin:0 0 4px;font-size:clamp(17px,1.8vw,21px);font-weight:700}
.rs-c-sc p{margin:0;display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--rs-mut)}
.rs-c-sc .rs-st{font-size:14px}
.rs-c-ctl{display:flex;align-items:center;gap:12px}
.rs-c-row{overflow:hidden;-webkit-mask-image:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent);mask-image:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent)}
.rs-c-row+.rs-c-row{margin-top:12px}
.rs-c-mv{display:flex;width:max-content;animation:rs-c-mq var(--d,60s) linear infinite}
.rs-c-row.r2 .rs-c-mv{animation-direction:reverse}
[dir=rtl] .rs-c-mv{animation-name:rs-c-mqr}
.rs-c:hover .rs-c-mv,.rs-c:focus-within .rs-c-mv,.rs-c.is-out .rs-c-mv,.rs-c.is-paused .rs-c-mv{animation-play-state:paused}
@keyframes rs-c-mq{to{transform:translateX(-50%)}}
@keyframes rs-c-mqr{to{transform:translateX(50%)}}
.rs-c ul{display:flex;gap:12px;margin:0;padding:0 6px;list-style:none}
.rs-c li{display:flex;align-items:center;gap:12px;width:max-content;max-width:min(380px,80vw);padding:10px 16px 10px 10px;padding-inline:10px 18px;border:1px solid var(--rs-line);border-radius:99px;background:#fff}
.rs-c li img,.rs-c li .rs-ph0{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#F6F1F3;flex:none}
.rs-c li>span{min-width:0}
.rs-c li q{display:block;font-size:13px;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;quotes:none}
.rs-c li small{display:flex;align-items:center;gap:6px;margin-top:2px;font-size:11.5px;color:var(--rs-mut);white-space:nowrap}
.rs-c li small .rs-st{font-size:11px}
.rs-c li small .rs-vf{font-size:0;gap:0}
@media (prefers-reduced-motion:reduce){.rs-c-mv{animation:none;width:auto}.rs-c-row{overflow-x:auto;scrollbar-width:none;-webkit-mask-image:none;mask-image:none}.rs-c ul[aria-hidden]{display:none}}
@media (max-width:639px){.rs-c-sc strong{font-size:36px}.rs-c li{max-width:78vw}}
</style>
<section class="sec rsx rs-c" data-rs="mq" aria-labelledby="rs-c-h"><div class="wrap">
  <div class="rs-c-hd">
    <div class="rs-c-sc">
      @if ($sum['score'])<strong>{{ $avg }}</strong>@endif
      <div><h2 id="rs-c-h">{{ __('store.home.reviews_heading') }}</h2>
      @if ($sum['score'])<p>@include('partials.review-showcase._stars', ['v' => $sum['average']])<span>{{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }}</span></p>@endif</div>
    </div>
    <div class="rs-c-ctl"><a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a>@include('partials.review-showcase._pp')</div>
  </div>
  <div role="region" aria-label="{{ $S::w('ticker') }}">
  @foreach ($rows as $ri => $row)
  <div class="rs-c-row r{{ $ri + 1 }}"><div class="rs-c-mv" style="--d:{{ max(30, count($row) * 7) }}s">
    @for ($copy = 0; $copy < 2; $copy++)
    <ul @if ($copy) aria-hidden="true" inert @endif>
      @foreach ($row as $c)
      <li>@if ($c['product'] && $c['product']['img'])<img src="{{ $c['product']['img'] }}" @if ($c['product']['srcset'] !== '') srcset="{{ $c['product']['srcset'] }}" sizes="40px" @endif width="40" height="40" alt="{{ $c['product']['name'] }}" loading="lazy" decoding="async">@else<span class="rs-ph0"></span>@endif<span><q dir="auto">{{ \Illuminate\Support\Str::limit($c['title'] !== '' ? $c['title'] : $c['text'], 60) }}</q><small>@include('partials.review-showcase._stars', ['v' => $c['rating']])<b dir="auto">{{ $c['name'] }}</b>@if ($c['city'] !== '')<span>· {{ $c['city'] }}</span>@endif @if ($c['verified'])@include('partials.review-showcase._vf')@endif</small></span></li>
      @endforeach
    </ul>
    @endfor
  </div></div>
  @endforeach
  </div>
</div></section>
