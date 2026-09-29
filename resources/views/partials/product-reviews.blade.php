{{--
    Product page reviews — ported from the finalized kbb-product.html.

    This is the theme's review markup (.rev-top / .rbar / .rfilters / .rgrid /
    .rcard), not the plugin's sr-* markup. The only sr-* piece on this page is
    the rating capsule in the buy box.

    Filtering and load-more are server-driven links so every state has a real
    URL, with the JS enhancing them in place.

    THIS PARTIAL IS AN ORPHAN and has been since the sr-* review wall replaced
    it: nothing includes it (tests/Feature/CssUrlInjectionTest.php says so too,
    and a repo-wide search for its name finds only that test and the manifest).
    It is kept in step with partials/reviews.blade.php anyway — the two draw the
    same column into the same kind of box, and a review photograph fix that
    landed in one and not the other would be waiting here for whoever mounts it.
--}}
@php use App\Support\CssUrl; use App\Support\Gradient; use App\Support\ImageVariants; @endphp
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
                {{-- (Lane IM2) THE 200w COPY, when one is on disk.

                     `.rp` is a 46px square (kbb-product.css:308) painted from a
                     review photograph, which off a handset is 1080x1920 and
                     ~530KB. It is a CSS background, so it cannot carry a srcset
                     and the width has to be chosen here — variantUrl() explains
                     why that is a single URL and why it returns the ORIGINAL
                     unchanged rather than a file that may not be there.

                     200 AND NOT 400: unlike the basket's `.kc-th`, this square
                     is not a setting. 46px is fixed in the stylesheet, so 200w
                     covers it at device-pixel-ratio 3 (46 x 3 = 138) with room
                     to spare and there is no slider that can outgrow it.

                     THE ORDER MATTERS. variantUrl() runs FIRST and CssUrl::value()
                     second, so the scheme gate still sees whatever is about to
                     be printed. variantUrl() cannot widen it: split() rejects
                     anything that is not this origin's own resizable file, and
                     a rejected reference comes back byte-for-byte as it went in.
                --}}
                @if ($photos)
                    <div class="rphotos">@foreach (array_slice($photos, 0, 4) as $j => $u)<div class="rp" style="background:#fff@if (($uCss = CssUrl::value(ImageVariants::variantUrl($u, 200))) !== '') url('{{ $uCss }}') center/cover@endif">@if (3 === $j && count($photos) > 4)<div class="more">{{ \App\Support\Bidi::number('+' . (count($photos) - 3)) }}</div>@endif</div>@endforeach</div>
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
