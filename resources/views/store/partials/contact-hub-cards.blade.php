{{-- The contact page's cards (Lane CT): App\Support\ContactPage::view()'s checked values. Icons are code constants; every other value is escaped. A card whose value is empty is not in $hub['cards'] at all. --}}
@if ($hub['cards'] !== [])
<section class="ctc ctc-top" aria-labelledby="ctc-reach">
    <h2 class="ctc-h" id="ctc-reach">{{ __('store.contact.reach_heading') }}</h2>
    <p class="ctc-sub">{{ __('store.contact.reach_intro') }}</p>
    <div class="ctc-cards">
@foreach ($hub['cards'] as $ctcCard)
        <a class="ctc-card" data-ct="{{ $ctcCard['key'] }}" href="{{ $ctcCard['href'] }}"@if ($ctcCard['external']) target="_blank" rel="noopener"@endif>
            <span class="ctc-ic" aria-hidden="true">{!! $ctcCard['icon'] !!}</span>
            <h3>{{ $ctcCard['title'] }}</h3>
            <p class="ctc-note">{{ $ctcCard['note'] }}</p>
            <p class="ctc-val">{{ $ctcCard['detail'] }}</p>
            <span class="ctc-go">{{ $ctcCard['action'] }}</span>
        </a>
@endforeach
    </div>
</section>
@endif
