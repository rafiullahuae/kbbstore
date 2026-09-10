{{-- Ported from kbb_checkout_reassurance_html(). --}}
@if ($settings->moduleEnabled('reassurance', true) && $settings->get('reassure_enabled', true))
@php
    $starsN = max(0, min(5, (int) $settings->get('reassure_stars', 5)));
    $rating = trim((string) $settings->get('reassure_rating_text', '4.8 · loved by 2,300+ UAE customers'));
    $auth   = trim((string) $settings->get('reassure_auth_text', '100% authentic K-beauty'));
@endphp
@if ($rating !== '' || $auth !== '')
<div class="kbb-reassure">
    @if ($rating !== '' || $starsN > 0)
        <div class="kr-line"><span class="kr-stars">@for ($i = 1; $i <= 5; $i++)<span class="kr-star{{ $i <= $starsN ? ' on' : '' }}">&#9733;</span>@endfor</span><span>{{ $rating }}</span></div>
    @endif
    @if ($auth !== '')
        <div class="kr-line"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4-3 7-7 8-4-1-7-4-7-8V6z"/><path d="M9 12l2 2 4-4"/></svg><span>{{ $auth }}</span></div>
    @endif
</div>
@endif
@endif
