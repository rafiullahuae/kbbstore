{{-- Ported from kbb_checkout_reassurance_html().

     THE RATING LINE IS THE REVIEWS TABLE, not a setting.

     It used to be `$settings->get('reassure_rating_text', '4.8 · loved by
     2,300+ UAE customers')` — an invented figure, shipped as the default, with
     no admin screen to change it, printed at the moment of payment on a shop
     that had no reviews at all. That is the same class of defect
     RatingsTellTheTruthTest exists for: a fabricated score speaking over the
     real review data on a page asking someone for money.

     App\Support\StoreRating computes it from APPROVED reviews only — the same
     question the product page and the home wall ask — and returns null until
     there are enough of them to mean anything, in which case this block simply
     does not render. Nothing is invented in its place; see the Gulf delivery
     line for the same restraint pointing the other way.

     `reassure_stars` is gone with it: the filled stars are the real average
     rounded, so the picture and the figure cannot disagree. --}}
@if ($settings->moduleEnabled('reassurance', true) && $settings->get('reassure_enabled', true))
@php
    $rating = \App\Support\StoreRating::summary();
    /* THE KEY WAS REAL AND THE OWNER COULD NOT REACH IT — Lane DR.

       `reassure_auth_text` looked settings-driven and was not: it appeared in
       no admin screen, in no seeder, and in no AdminController::SETTING_RULES
       entry, and updateSettings() REJECTS any key that is not in that list. So
       the default below was the shipped value and the only value, printed at
       the moment of payment on every shop that has ever run this code — which
       is the same defect as a literal in the template, wearing a setting's
       clothes. The key is in SETTING_RULES now.

       Read through App\Support\TrustClaims so that the empty case is decided in
       one place: SettingsService::get() returns its default only when the ROW
       IS ABSENT, and an owner who clears the box stores '', which must mean
       "do not say this" and not "say the shipped line again". */
    $auth   = \App\Support\TrustClaims::text($settings, 'reassure_auth_text') ?? '';
@endphp
@if ($rating !== null || $auth !== '')
<div class="kbb-reassure">
    @if ($rating !== null)
        <div class="kr-line"><span class="kr-stars">@for ($i = 1; $i <= 5; $i++)<span class="kr-star{{ $i <= $rating['stars'] ? ' on' : '' }}">&#9733;</span>@endfor</span><span>{{ \App\Support\StoreRating::line() }}</span></div>
    @endif
    @if ($auth !== '')
        <div class="kr-line"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4-3 7-7 8-4-1-7-4-7-8V6z"/><path d="M9 12l2 2 4-4"/></svg><span>{{ $auth }}</span></div>
    @endif
</div>
@endif
@endif
