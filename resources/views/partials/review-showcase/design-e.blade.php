{{-- Lane RD · design E · SCORE + FEATURED (preview only, not wired).
     The big number, its five bars and the share of 4-5 star reviews, all from
     ReviewWall::summary() -- the statement /reviews/ prints its own headline
     from -- beside three featured reviews. The 5 and 4 star bars open
     /reviews/ already filtered to them (the filters that page has). --}}
@php
    $S = \App\Support\ReviewShowcase::class;
    $sum = $rs['summary'];
    $cards = array_slice($rs['items'], 0, 3);
    $avg = \App\Support\Bidi::number(number_format((float) $sum['average'], 1));
    $good = $sum['total'] ? (int) round(($sum['bars'][5]['n'] + $sum['bars'][4]['n']) / $sum['total'] * 100) : 0;
@endphp
<style>
.rs-e-g{display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:stretch}
.rs-e-s{background:var(--rs-cream);border-radius:20px;padding:26px 24px;display:flex;flex-direction:column;gap:14px}
.rs-e-s h2{margin:0;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--rs-pink)}
.rs-e-n{display:flex;align-items:flex-end;gap:10px}
.rs-e-n strong{font-size:56px;font-weight:700;line-height:.9;letter-spacing:-.02em}
.rs-e-n span{font-size:13px;color:var(--rs-mut);padding-bottom:4px}
.rs-e-s>.rs-st{font-size:19px}
.rs-e-s p{margin:0;font-size:13px;color:var(--rs-mut)}
.rs-e-s p b{color:var(--rs-ink)}
.rs-e-b{display:flex;flex-direction:column;gap:5px;margin:2px 0}
.rs-e-r{display:grid;grid-template-columns:30px 1fr 30px;align-items:center;gap:10px;font-size:12px;color:var(--rs-mut);padding:3px 4px;margin:0 -4px;border-radius:6px}
a.rs-e-r:hover{background:#fff;color:var(--rs-ink)}
.rs-e-r i{height:6px;border-radius:9px;background:#EFE6EA;overflow:hidden;display:block}
.rs-e-r i::before{content:"";display:block;height:100%;width:var(--p);background:var(--rs-gold);border-radius:inherit}
.rs-e-r span:last-child{text-align:end}
.rs-e-s .rs-all{align-self:flex-start;margin-top:auto}
.rs-e-c{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.rs-e-k{border:1px solid var(--rs-line);border-radius:20px;padding:22px;display:flex;flex-direction:column;gap:12px;background:#fff;min-width:0}
.rs-e-hd{display:flex;align-items:center;gap:10px}
.rs-e-hd .rs-who{flex-direction:column;align-items:flex-start;gap:2px}
.rs-e-k .rs-st{font-size:14px}
.rs-e-k h3{margin:0;font-size:15px;font-weight:600;line-height:1.35}
.rs-e-k p{margin:0;font-size:13.5px;line-height:1.6;color:var(--ink-2,#5E545A);display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden}
.rs-e-k .rs-pr{margin-top:auto;padding:10px;background:#FBF8F9;border-radius:12px}
.rs-e-k time{font-size:11.5px;color:var(--rs-mut)}
@media (max-width:1023px){.rs-e-g{grid-template-columns:1fr}.rs-e-c{grid-template-columns:repeat(2,1fr)}.rs-e-k:nth-child(3){display:none}}
@media (max-width:639px){.rs-e-s{display:grid;grid-template-columns:auto 1fr;gap:10px 18px;padding:20px}.rs-e-s h2,.rs-e-s .rs-all{grid-column:1/-1}.rs-e-b{grid-row:2/5;grid-column:2}.rs-e-n strong{font-size:46px}.rs-e-c{grid-template-columns:1fr}.rs-e-k{padding:18px}.rs-e-k:nth-child(3){display:none}}
</style>
<section class="sec rsx rs-e" aria-labelledby="rs-e-h"><div class="wrap"><div class="rs-e-g">
  <div class="rs-e-s">
    <h2 id="rs-e-h">{{ $S::w('eyebrow') }}</h2>
    @if ($sum['score'])
    <div class="rs-e-n"><strong>{{ $avg }}</strong><span>{{ $S::w('out_of') }}</span></div>
    @include('partials.review-showcase._stars', ['v' => $sum['average']])
    <p>{{ trans_choice('store.home.reviews_count', (int) $sum['total'], ['formatted' => number_format((int) $sum['total'])]) }} · <b>{{ \App\Support\Bidi::number($good.'%') }}</b> 4–5 ★</p>
    <div class="rs-e-b">
      @foreach ($sum['bars'] as $star => $bar)
        @if (in_array($star, [5, 4], true))
        <a class="rs-e-r" href="{{ $rs['all'] }}?rfilter={{ $star }}" aria-label="{{ $S::w('stars_n', ['n' => $star]) }}: {{ $bar['n'] }}"><span>{{ $star }} ★</span><i style="--p:{{ $bar['pct'] }}%"></i><span>{{ $bar['n'] }}</span></a>
        @else
        <div class="rs-e-r"><span>{{ $star }} ★</span><i style="--p:{{ $bar['pct'] }}%"></i><span>{{ $bar['n'] }}</span></div>
        @endif
      @endforeach
    </div>
    @endif
    <a class="rs-all" href="{{ $rs['all'] }}">{{ $S::w('see_all') }}@include('partials.review-showcase._arrow')</a>
  </div>
  <div class="rs-e-c">
    @foreach ($cards as $c)
    <article class="rs-e-k">
      <div class="rs-e-hd"><span class="rs-av">{{ $c['initial'] }}</span>@include('partials.review-showcase._who', ['date' => false])</div>
      <div>@include('partials.review-showcase._stars', ['v' => $c['rating']]) <time datetime="{{ $c['iso'] }}">&nbsp;{{ $c['date'] }}</time></div>
      @if ($c['title'] !== '')<h3 dir="auto">{{ $c['title'] }}</h3>@endif
      <p dir="auto">{{ $c['text'] }}</p>
      @include('partials.review-showcase._product')
    </article>
    @endforeach
  </div>
</div></div></section>
