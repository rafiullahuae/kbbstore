{{--
    Brands — the directory at /korean-skincare-brands/, and a single brand's
    landing page at /korean-skincare-brands/{slug}/.

    One view, two modes, keyed on $brand. They share the breadcrumb, the
    heading block and the stylesheet below, and splitting them into two files
    would mean maintaining that CSS twice.

    URL Contract U-05: neither mode is a brand *archive*. The filterable
    product listing stays at /shop/?filter_brands={slug} — Brand::url() — and
    both modes link to it rather than reproducing it.
--}}
@extends('layouts.store')
@section('title', $brand ? $brand->name : 'All brands')

@push('styles')
    {{-- Only when there is a banner to draw. A brand with none configured —
         which is every brand until the owner turns one on — downloads nothing
         extra for a feature it is not using. --}}
    @if ($banner ?? null)
        @vite('resources/css/kbb/kbb-banner.css')
    @endif
@endpush

@section('content')
@php use App\Support\Url; @endphp

<div class="brw">
    @if ($brand)
        <nav class="brw-crumb" aria-label="Breadcrumb">
            <a href="{{ Url::to('/') }}">Home</a> <span>&rsaquo;</span>
            <a href="{{ Url::to('/korean-skincare-brands/') }}">Brands</a> <span>&rsaquo;</span>
            <span aria-current="page">{{ $brand->name }}</span>
        </nav>

        {{--
            The banner, when the owner has turned one on for this brand.

            It goes above the hero rather than replacing it: the hero's job is
            the logo and the link onward to the listing, and those are still
            wanted. What it does take over is the page's <h1> and standfirst,
            which is why the two below are conditional — a banner heading and a
            .brw-h1 both claiming to be the page's heading is two <h1>s on one
            document, and the one a crawler picks would be the one the owner
            did not design.

            :contained="false" because .brw already supplies the max-width and
            the side gutter.
        --}}
        <x-kbb-banner :banner="$banner ?? null" :contained="false" />

        <div class="brw-hero">
            <span class="brw-logo brw-logo--lg">
                @if ($brand->logo)
                    <img src="{{ $brand->logo }}" alt="{{ $brand->name }}" decoding="async">
                @else
                    <span class="brw-initial">{{ mb_strtoupper(mb_substr($brand->name, 0, 1)) }}</span>
                @endif
            </span>
            <div class="brw-hero-txt">
                @unless ($banner ?? null)
                    <h1 class="brw-h1">{{ $brand->name }}</h1>
                    @if ($brand->description)
                        <p class="brw-sub">{{ strip_tags($brand->description) }}</p>
                    @endif
                @endunless
                {{--
                    The listing, not a second archive. Built from Brand::url()
                    so this link and the directory's tiles can never drift
                    apart, and so U-05 has exactly one place to change.
                --}}
                <a class="brw-cta" href="{{ $brand->url() }}">Shop all {{ $brand->name }}</a>
            </div>
        </div>

        @if ($products->isEmpty())
            <p class="brw-empty">Nothing from this brand is in the shop right now.</p>
        @else
            <x-product-grid :products="$products" heading="Popular right now"
                            :more-url="$brand->url()" more-label="View all" />
        @endif
    @else
        <nav class="brw-crumb" aria-label="Breadcrumb">
            <a href="{{ Url::to('/') }}">Home</a> <span>&rsaquo;</span> <span aria-current="page">Brands</span>
        </nav>

        <h1 class="brw-h1">All brands</h1>
        <p class="brw-sub">{{ $brands->count() }} brands, {{ $stocked }} with products in the shop right now.</p>

        @if ($brands->isEmpty())
            <p class="brw-empty">No brands have been added yet.</p>
        @else
            {{--
                The count-derived numbers the grid is built from — see
                BrandController::gridMinimum(). --brw-count-min is the
                minmax() floor, which is the only thing deciding the column
                count: four brands get wide tiles across one tidy row,
                ninety-three get narrow ones. --brw-count-cap stops a handful
                of brands from being stretched across the whole 1180px
                container with an empty tail.

                They are named apart from the --brw-min/--brw-cap the rule
                actually reads, on purpose. An inline custom property beats
                every stylesheet declaration of the same name, media query
                included, so setting --brw-min here directly would make the
                phone breakpoint below a dead rule.
            --}}
            <div class="brw-grid" style="--brw-count-min:{{ (int) $gridMin }}px;--brw-count-cap:{{ $brands->count() * ((int) $gridMin + 12) }}px">
                @foreach ($brands as $item)
                    @php
                        $n = (int) $item->products_count;
                        // logos falls back to the name rather than rendering
                        // an empty tile, which is the ordinary case until the
                        // operator has uploaded ninety-three logos.
                        $showLogo = $display !== 'names' && $item->logo;
                        $showInitial = $display === 'auto' && ! $item->logo;
                        $showName = $display !== 'logos' || ! $item->logo;
                    @endphp
                    {{--
                        Every tile goes to the brand's own page now, including
                        the empty ones: the landing page still has the brand's
                        name, logo and description on it, which is more use
                        than the bare /shop/ the empty tiles used to fall back
                        to.
                    --}}
                    <a class="brw-card{{ $n === 0 ? ' is-empty' : '' }}"
                       href="{{ Url::to('/korean-skincare-brands/' . $item->slug . '/') }}">
                        @if ($showLogo || $showInitial)
                            <span class="brw-logo">
                                @if ($showLogo)
                                    <img src="{{ $item->logo }}" alt="{{ $item->name }}" loading="lazy" decoding="async">
                                @else
                                    <span class="brw-initial">{{ mb_strtoupper(mb_substr($item->name, 0, 1)) }}</span>
                                @endif
                            </span>
                        @endif
                        @if ($showName)
                            <span class="brw-name">{{ $item->name }}</span>
                        @endif
                        <span class="brw-count">{{ $n > 0 ? $n . ' ' . \Illuminate\Support\Str::plural('product', $n) : 'Coming soon' }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</div>

@push('scripts')
<style>
.brw{max-width:1180px;margin:0 auto;padding:22px 18px 60px}
.brw-crumb{font-size:12px;color:var(--muted);margin-bottom:14px}
.brw-crumb a{color:var(--muted)}
.brw-crumb span{margin:0 5px}
.brw-h1{font-size:26px;margin:0 0 5px;color:var(--ink)}
.brw-sub{font-size:13px;color:var(--muted);margin:0 0 24px}
.brw-empty{font-size:14px;color:var(--muted)}
.brw-grid{display:grid;gap:12px;
  --brw-min:var(--brw-count-min,158px);
  --brw-cap:var(--brw-count-cap,100%);
  grid-template-columns:repeat(auto-fill,minmax(min(var(--brw-min),100%),1fr));
  max-width:min(100%,var(--brw-cap))}
.brw-card{display:flex;flex-direction:column;align-items:center;gap:9px;text-align:center;
  padding:20px 14px 16px;border:1px solid var(--line);border-radius:12px;background:#fff;
  text-decoration:none;transition:border-color .16s,transform .16s}
.brw-card:hover{border-color:var(--blush);transform:translateY(-2px)}
.brw-card.is-empty{opacity:.55}
.brw-logo{width:62px;height:62px;border-radius:50%;background:var(--cream);display:grid;place-items:center;overflow:hidden;flex:none}
.brw-logo img{width:100%;height:100%;object-fit:contain;padding:7px}
.brw-logo--lg{width:96px;height:96px}
.brw-initial{font-size:22px;color:var(--pink)}
.brw-logo--lg .brw-initial{font-size:34px}
.brw-name{font-size:13.5px;color:var(--ink);line-height:1.35}
.brw-count{font-size:11px;color:var(--muted)}
.brw-hero{display:flex;align-items:center;gap:20px;margin-bottom:28px}
.brw-hero .brw-h1{margin-bottom:7px}
.brw-hero .brw-sub{margin-bottom:14px;max-width:62ch}
.brw-cta{display:inline-block;padding:10px 20px;border-radius:999px;background:var(--pink);
  color:#fff;font-size:13px;text-decoration:none}
.brw-cta:hover{filter:brightness(.94)}
@media (max-width:520px){
  /* Below this the count-derived floor stops helping: a 240px tile on a
     375px screen is one column of very tall cards. Two columns of 132px is
     the sane phone layout at every brand count, so the phone overrides both
     the floor and the cap rather than the whole rule. */
  .brw-grid{--brw-min:132px;--brw-cap:100%;gap:10px}
  .brw-logo{width:52px;height:52px}
  .brw-logo--lg{width:72px;height:72px}
  .brw-hero{flex-direction:column;align-items:flex-start;gap:14px}
}
</style>
@endpush
@endsection
