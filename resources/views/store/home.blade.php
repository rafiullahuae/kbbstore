@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; use App\Support\Gradient; @endphp

@section('title', 'K-Beauty Bliss · Authentic Korean skincare in the UAE')

@push('styles')
    @vite('resources/css/kbb/kbb-grid-skins.css')
@endpush

@section('content')
<div class="kbb-home">

{{-- HERO --}}
@unless ($sections->hidden('hero'))
<section class="sec {{ $sections->classFor('hero') }}" style="padding-top:14px"><div class="wrap">
  <div class="slider" id="slider">
    <div class="slides" id="slides">
      @foreach ($banners as $b)
        <a class="sl" href="{{ Url::to($b['url']) }}" style="background:{{ $b['gradient'] }}">
          <div class="sl-t">
            <span class="k">{{ $b['kicker'] }}</span>
            <h2>{!! $b['heading'] !!}</h2>
            <p>{{ $b['text'] }}</p>
            <span class="b">{{ $b['button'] }}</span>
          </div>
          <div class="sl-i" style="background:{{ $b['panel'] }}"></div>
        </a>
      @endforeach
    </div>
    <button class="sarr prev" id="sprev" type="button" aria-label="Previous">‹</button>
    <button class="sarr next" id="snext" type="button" aria-label="Next">›</button>
    <div class="sdots" id="sdots"></div>
  </div>

  @unless ($sections->hidden('delivery'))
  <div class="delivery {{ $sections->classFor('delivery') }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 7h13v10H2z"/><path d="M15 10h4l3 3.5V17h-7z"/><circle cx="6" cy="19" r="1.6"/><circle cx="18" cy="19" r="1.6"/></svg>
    <b>{{ $settings->get('delivery_default_text', '1–3 days delivery all over UAE') }}</b><span>·</span>
    <span>Free delivery over {!! Money::format((int) $settings->get('free_shipping_threshold', 19900)) !!}</span>
  </div>
  @endunless

  @unless ($sections->hidden('ticker'))
  <div class="tick {{ $sections->classFor('ticker') }}"><div>
    @for ($i = 0; $i < 2; $i++)
      <span>🎁 {!! $settings->get('home_ticker', 'Anniversary <b>30% off</b> — code <b>GLOW30</b> at checkout') !!}</span><span>·</span>
      <span>Free delivery over <b>AED 199</b></span><span>·</span>
      <span>1–3 day delivery across the UAE</span><span>·</span>
    @endfor
  </div></div>
  @endunless
</div></section>
@endunless

{{-- CATEGORIES --}}
@unless ($sections->hidden('categories'))
<section class="sec {{ $sections->classFor('categories') }}" style="padding-top:8px"><div class="wrap">
  <div class="cats">
    @foreach ($categories as $c)
      <a class="ct" href="{{ $c->url() }}">
        <div class="im" style="background:{{ Gradient::for($c->name) }}"></div>
        <b>{{ $c->name }}</b><span class="n">{{ $c->products_count }} products</span>
      </a>
    @endforeach
  </div>
</div></section>
@endunless

{{-- BUNDLES --}}
@unless ($sections->hidden('bundles'))
<section class="sec {{ $sections->classFor('bundles') }}" style="padding-top:8px"><div class="wrap">
  <div class="sh"><div><h2>Big savings bundles <span class="cnt">{{ $rails['bundles']->count() }} sets</span></h2>
    <p>Complete routines, priced below the sum of their parts.</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?cat=skincare-sets') }}">All sets</a></div>
  @include('partials.home.grid', ['items' => $rails['bundles'], 'skin' => $sections->skinFor('bundles'), 'catLabel' => 'Skincare sets'])
</div></section>
@endunless

{{-- RECOMMENDED --}}
@unless ($sections->hidden('recommended'))
<section class="sec {{ $sections->classFor('recommended') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>Recommended for you <span class="cnt">Updated daily</span></h2>
    <p>Handpicked K-beauty essentials for glowing skin.</p></div>
    <a class="lnk" href="{{ Url::to('/shop/') }}">Shop more</a></div>
  @include('partials.home.grid', ['items' => $rails['recommended'], 'skin' => $sections->skinFor('recommended'), 'catLabel' => 'Recommended'])
</div></section>
@endunless

{{-- ROUTINE --}}
@unless ($sections->hidden('routine'))
<section class="sec tinted {{ $sections->classFor('routine') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>Build your routine <span class="cnt">6 steps</span></h2></div>
    <a class="lnk" href="{{ Url::to('/skincare-guide/') }}">Routine guide</a></div>
  <div class="rsteps">
    @foreach ($routine as $step)
      <a class="rstep" href="{{ Url::to('/product-category/' . $step['slug'] . '/') }}">
        <span class="rn">{{ $step['n'] }}</span>
        <div class="rb"><b>{{ $step['title'] }}</b><span>{{ $step['note'] }}</span></div>
        @if ($step['pick'])
          <div class="rp"><i style="background:{{ Gradient::for($step['pick']->name) }}"></i>
            <span>{{ $step['pick']->brand?->name }}<br><b>{!! Money::format($step['pick']->effectivePrice()) !!}</b></span></div>
        @endif
      </a>
    @endforeach
  </div>
  @if ($routineTotal > 0)
    <a class="rall" href="{{ Url::to('/shop/') }}">Add the whole routine · <b>{!! Money::format($routineTotal) !!}</b></a>
  @endif
</div></section>
@endunless

{{-- SKIN QUIZ --}}
@unless ($sections->hidden('quiz'))
<section class="sec {{ $sections->classFor('quiz') }}" style="padding-top:0"><div class="wrap">
  <div class="quiz">
    <div class="q-left">
      <span class="q-k">Two minutes · free</span>
      <h2>Not sure where<br>to start?</h2>
      <p>Answer five questions and we will build a routine from what we actually stock — with the reasoning behind every pick.</p>
      <div class="q-why">
        <div><b>{{ number_format($catalogueCount) }}</b><span>products matched</span></div>
        <div><b>5</b><span>quick questions</span></div>
        <div><b>0</b><span>cost, no signup</span></div>
      </div>
    </div>
    <form class="q-card" method="get" action="{{ Url::to('/skin-quiz/') }}">
      <div class="q-top"><span class="q-step">Question 1 of 5</span><div class="q-bar"><i style="width:20%"></i></div></div>
      <h3 class="q-q">How does your skin usually feel by mid-afternoon?</h3>
      <div class="q-opts">
        @foreach ([['oily','Shiny all over','Oily'],['dry','Tight or flaky','Dry'],['combo','Oily T-zone, dry cheeks','Combination'],['sensitive','Red or stinging','Sensitive'],['normal','Comfortable, no change','Normal']] as [$v, $l, $t])
          <label class="q-o"><input type="radio" name="skin" value="{{ $v }}"><span class="d"></span><span class="l"><b>{{ $l }}</b><span>{{ $t }}</span></span></label>
        @endforeach
      </div>
      <div class="q-foot"><span class="q-hint">Pick the closest one</span><button class="q-next" type="submit">Continue →</button></div>
    </form>
  </div>
</div></section>
@endunless

{{-- BRANDS --}}
@unless ($sections->hidden('brands'))
<section class="sec tinted {{ $sections->classFor('brands') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>Top brands <span class="cnt">{{ $brandTotal }} brands</span></h2>
    <p>Korean brands, all sourced direct.</p></div>
    <a class="lnk" href="{{ Url::to('/brands/') }}">All brands</a></div>
  <div class="brands">
    @foreach ($brands as $b)
      <a class="bd" href="{{ $b->url() }}"><div class="bname"><span class="bn">{{ $b->name }}</span><span class="bc">{{ $b->products_count }}</span></div></a>
    @endforeach
  </div>
</div></section>
@endunless

{{-- SPOTTED --}}
@unless ($sections->hidden('spotted'))
<section class="sec {{ $sections->classFor('spotted') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>#KBeautyBliss spotted <span class="cnt">Shoppable</span></h2>
    <p>Real routines from our community.</p></div>
    <a class="lnk" href="{{ Url::to('/shop/') }}">Discover more</a></div>
  <div class="ugc">
    @foreach ($rails['best1']->take(4) as $p)
      <a href="{{ $p->url() }}"><div class="im" style="background:{{ $p->image ? "#fff url('" . e($p->image) . "') center/cover" : Gradient::for($p->name) }}">
        <span class="shop"><b>{{ $p->name }}</b><span>{!! Money::format($p->effectivePrice()) !!}</span></span></div></a>
    @endforeach
  </div>
</div></section>
@endunless

{{-- BEST SELLERS --}}
@unless ($sections->hidden('bestsellers'))
<section class="sec {{ $sections->classFor('bestsellers') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>Best sellers <span class="cnt">This month</span></h2>
    <p>The products customers keep coming back for.</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?orderby=popularity') }}">Shop more</a></div>
  @include('partials.home.grid', ['items' => $rails['best1'], 'skin' => $sections->skinFor('bestsellers'), 'catLabel' => 'Best sellers', 'rank' => true])
</div></section>
@endunless

{{-- FLASH --}}
@unless ($sections->hidden('flash'))
<section class="sec {{ $sections->classFor('flash') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>Flash sale · up to 50% off <span class="cnt">While stocks last</span></h2>
    <p>Deep cuts, limited stock.</p></div>
    <a class="lnk" href="{{ Url::to('/shop/?on_sale=1') }}">See all</a></div>
  @include('partials.home.grid', ['items' => $rails['flash'], 'skin' => $sections->skinFor('flash'), 'catLabel' => 'Flash sale'])
</div></section>
@endunless

{{-- BLOG --}}
@unless ($sections->hidden('blog'))
@if ($posts->isNotEmpty())
<section class="sec tinted {{ $sections->classFor('blog') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>Skincare guide <span class="cnt">Journal</span></h2><p>Read before you buy.</p></div>
    <a class="lnk" href="{{ Url::to('/skincare-guide/') }}">All articles</a></div>
  <div class="blog">
    @foreach ($posts as $post)
      <a class="bl" href="{{ Url::to('/skincare-guide/' . $post->slug . '/') }}">
        <div class="im" style="background:{{ $post->image ? "#fff url('" . e($post->image) . "') center/cover" : Gradient::for($post->title) }}">
          @if ($post->category)<span class="chip">{{ $post->category }}</span>@endif</div>
        <h3>{{ $post->title }}</h3>
        <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) ($post->excerpt ?: $post->content)), 110) }}</p>
        <span class="meta">{{ $post->read_minutes ?? 5 }} min read · {{ $post->published_at?->format('j M') }}</span>
      </a>
    @endforeach
  </div>
</div></section>
@endif
@endunless

{{-- ABOUT --}}
@unless ($sections->hidden('about'))
<section class="sec {{ $sections->classFor('about') }}" style="padding-top:0"><div class="wrap">
  <div class="about">
    <div class="im"></div>
    <div>
      <h2>About K-Beauty Bliss</h2>
      <p>{{ $settings->get('about_text', 'We are passionate about bringing the best of Korean beauty to skincare enthusiasts across the UAE. Every item is curated to meet the highest standards of quality and effectiveness.') }}</p>
      <div class="astats">
        <div><b>{{ number_format($catalogueCount) }}</b><span>products stocked</span></div>
        <div><b>{{ $brandTotal }}</b><span>Korean brands</span></div>
        <div><b>{{ $reviews['total'] > 999 ? round($reviews['total'] / 1000, 1) . 'k+' : $reviews['total'] }}</b><span>verified reviews</span></div>
        <div><b>1–3</b><span>day delivery</span></div>
      </div>
      <a class="lnk" style="display:inline-block;margin-top:18px" href="{{ Url::to('/about/') }}">Our story</a>
    </div>
  </div>
</div></section>
@endunless

{{-- REVIEWS --}}
@unless ($sections->hidden('reviews'))
@if ($reviews['total'] > 0)
<section class="sec {{ $sections->classFor('reviews') }}" style="padding-top:0"><div class="wrap">
  <div class="sh"><div><h2>What our customers say <span class="cnt">{{ number_format($reviews['average'], 1) }} average</span></h2>
    <p>{{ number_format($reviews['total']) }} verified reviews from real orders.</p></div>
    <a class="lnk" href="{{ Url::to('/reviews/') }}">Read all</a></div>

  <div class="rev-top">
    <div class="rev-score"><div class="big">{{ number_format($reviews['average'], 1) }}</div>
      <div class="stars">@for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= round($reviews['average']) ? 'f' : '' }}">★</span>@endfor</div>
      <small>{{ number_format($reviews['total']) }} reviews</small></div>
    <div class="rbars">
      @foreach ($reviews['bars'] as $star => $pct)
        <div class="rbar"><span class="t">{{ $star }}★</span><span class="track"><i style="width:{{ $pct }}%"></i></span><span class="n">{{ $pct }}%</span></div>
      @endforeach
    </div>
  </div>

  <div class="rfilters">
    @foreach ([['all', 'All'], ['photos', 'With photos'], ['5', '5★'], ['4', '4★'], ['helpful', 'Most helpful']] as [$k, $l])
      <a class="rfilter{{ $k === 'all' ? ' on' : '' }}" href="{{ Url::to('/reviews/?rfilter=' . $k) }}">{{ $l }}</a>
    @endforeach
  </div>

  <div class="rgrid">
    @foreach ($reviews['items'] as $r)
      <div class="rcard">
        <div class="rh"><span class="av" style="background:{{ Gradient::for($r->author_name ?: '?') }}">{{ mb_substr($r->author_name ?: '?', 0, 1) }}</span>
          <div class="rwho"><div class="rline"><span class="nm">{{ $r->author_name }}</span>@if ($r->verified)<span class="vf">✓ Verified</span>@endif</div>
            <span class="rstars">@for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= (int) $r->rating ? 'f' : '' }}">★</span>@endfor</span></div></div>
        @if ($r->product)<div class="rprod">{{ $r->product->name }}</div>@endif
        <div class="rtext">{{ \Illuminate\Support\Str::limit(strip_tags((string) $r->content), 150) }}</div>
        <div class="rf"><span>{{ $r->created_at?->diffForHumans() }}</span>@if ($r->helpful)<span class="cnt3">👍 {{ (int) $r->helpful }}</span>@endif</div>
      </div>
    @endforeach
  </div>
</div></section>
@endif
@endunless

{{-- TRUST --}}
@unless ($sections->hidden('trust'))
<section class="sec {{ $sections->classFor('trust') }}" style="padding-top:0"><div class="wrap">
  <div class="trust"><div class="g">
    @foreach ([
        ['Fast UAE shipping', '1–3 days, free over AED 199', '<path d="M2 7h13v10H2z"/><path d="M15 10h4l3 3.5V17h-7z"/><circle cx="6" cy="19" r="1.6"/><circle cx="18" cy="19" r="1.6"/>'],
        ['Secure payments', 'Card, Tabby, Tamara and COD', '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
        ['100% original', 'Direct from brands and trusted suppliers', '<path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>'],
        ['24/7 support', 'WhatsApp +971 58 505 2611', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
    ] as [$t, $d, $icon])
      <div class="i"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">{!! $icon !!}</svg></span>
        <div><b>{{ $t }}</b><span>{{ $d }}</span></div></div>
    @endforeach
  </div></div>
</div></section>
@endunless

{{-- NEWSLETTER --}}
@unless ($sections->hidden('newsletter'))
@php
    // Appearance → Homepage still decides whether this shows; Growth & Marketing
    // → Newsletter decides what it says. Deliberately two screens, one switch.
    $nl = app(\App\Services\NewsletterSettings::class);
@endphp
<section class="sec {{ $sections->classFor('newsletter') }}" style="padding-top:0"><div class="wrap">
  <div class="nl" style="{{ $nl->cssVariables() }}">
    <div class="kick">{{ $nl->get('nl_eyebrow') }}</div>
    <h2>{{ $nl->get('nl_heading') }}</h2>
    <p class="lede" style="margin:0 auto">{{ $nl->get('nl_subheading') }}</p>
    <form class="f" method="post" action="{{ Url::to('/api/subscribe') }}" data-kbb-subscribe>@csrf
      <input type="email" name="email" placeholder="{{ $nl->get('nl_placeholder') }}" required>
      <button class="btn btn-p" type="submit">{{ $nl->get('nl_button') }}</button>
    </form>
    @if (session('kbb_subscribed'))
      <p class="nl-note" role="status">{{ session('kbb_subscribed') }}</p>
    @elseif (session('kbb_subscribe_error'))
      <p class="nl-note nl-note--bad" role="alert">{{ session('kbb_subscribe_error') }}</p>
    @endif
  </div>
</div></section>
@endunless

</div>
@endsection
