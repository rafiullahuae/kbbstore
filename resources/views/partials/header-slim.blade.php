@php use App\Support\Url; @endphp

{{-- T-CHROME-1: checkout hides the site header and shows this instead. --}}
<header class="co-head">
    <div class="wrap">
        <a class="logo" href="{{ Url::to('/') }}">K-Beauty<span>Bliss</span></a>
        <div class="co-secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            Secure checkout
        </div>
    </div>
</header>
