{{--
    Product page reviews — ported from the finalized kbb-product.html.

    This is the theme's review markup (.rev-top / .rbar / .rfilters / .rgrid /
    .rcard), not the plugin's sr-* markup. The only sr-* piece on this page is
    the rating capsule in the buy box.

    Filtering and load-more are server-driven links so every state has a real
    URL, with the JS enhancing them in place.
--}}
@php use App\Support\Gradient; @endphp
@php
    $filters = [
        ['all', __('store.reviews.filter_all')], ['photos', __('store.reviews.filter_photos')],
        ['5', '5★'], ['4', '4★'], ['3', '3★'], ['helpful', __('store.reviews.filter_helpful')],
    ];
    $current = request('rfilter', 'all');
    $shown = (int) request('rshow', 4);
@endphp

<section class="sec" id="reviews">
    <div class="eyebrow">{{ trans_choice('store.reviews.loved_by', (int) $summary['total'], ['formatted' => number_format($summary['total'])]) }}</div>
    <h2>{{ __('store.reviews.short_heading') }}</h2>

    @if ($summary['total'] > 0)
    <div class="rev-top">
        <div class="rev-score"><div class="big">{{ number_format($summary['average'], 1) }}</div><div class="stars" id="revStars">@for ($i = 1; $i <= 5; $i++){!! $i <= round($summary['average']) ? '<span class="f">★</span>' : '<span>★</span>' !!}@endfor</div><small>{{ trans_choice('store.reviews.review_count_formatted', (int) $summary['total'], ['formatted' => number_format($summary['total'])]) }}</small></div>
        <div>
            @foreach ($summary['bars'] as $star => $bar)
                <div class="rbar"><span class="t">{{ $star }}★</span><span class="track"><i style="width:{{ $bar['pct'] }}%"></i></span><span class="n">{{ $bar['pct'] }}%</span></div>
            @endforeach
        </div>
    </div>

    <div class="rfilters" id="rfilters">
        @foreach ($filters as [$key, $label])
            <button class="rfilter{{ $current === $key ? ' on' : '' }}" data-f="{{ $key }}" type="button">{{ $label }}</button>
        @endforeach
    </div>

    <div class="rgrid" id="rgrid">
        @foreach ($reviews as $i => $r)
            @php $photos = is_array($r->images) ? array_values(array_filter($r->images)) : []; @endphp
            <div class="rcard" data-r="{{ $i }}">
                <div class="rh"><span class="av">{{ Gradient::initials($r->author_name ?: '?') }}</span><div><div class="nm">{{ $r->author_name }}</div>@if ($r->verified)<div class="vf">{{ __('store.reviews.verified_badge') }}</div>@endif</div><span class="rstars stars">@for ($s = 1; $s <= 5; $s++){!! $s <= (int) $r->rating ? '<span class="f">★</span>' : '<span>★</span>' !!}@endfor</span></div>
                @if ($photos)
                    <div class="rphotos">@foreach (array_slice($photos, 0, 4) as $j => $u)<div class="rp" style="background:#fff url('{{ $u }}') center/cover">@if (3 === $j && count($photos) > 4)<div class="more">+{{ count($photos) - 3 }}</div>@endif</div>@endforeach</div>
                @endif
                <div class="rtext">{{ strip_tags($r->content) }}</div>
                <div class="rf"><span>{{ __('store.reviews.time_ago', ['time' => $r->created_at?->diffForHumans(null, true)]) }}</span><span class="cnt">👍 {{ (int) $r->helpful }}</span></div>
            </div>
        @endforeach
    </div>

    @if ($summary['total'] > $shown)
        <a class="loadmore" id="revMore" style="display:block;margin:24px auto 0;border:1.5px solid var(--pink);color:var(--pink-deep);font-weight:600;font-size:14px;padding:12px 28px;border-radius:99px;background:none;text-align:center;max-width:220px" href="{{ request()->fullUrlWithQuery(['rshow' => $shown + 8]) }}#reviews">{{ __('store.reviews.load_more') }}</a>
    @endif
    @else
        <p style="color:var(--muted);font-size:14px">{{ __('store.reviews.empty') }}</p>
    @endif

    <div style="text-align:center"><button class="writebtn" type="button" data-sr-open>{{ __('store.reviews.write_link') }}</button></div>
</section>
