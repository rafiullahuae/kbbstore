{{-- The contact page's cards (Lane CT): App\Support\ContactPage::view()'s checked values. No visible heading (the owner: "remove the get in touch heading … contact us is fine"); its words name the section for a screen reader. Icons are code constants; every other value is escaped. A card whose value is empty is not in $hub['cards'] at all. --}}
@if ($hub['cards'] !== [])
<section class="ctc ctc-top" aria-label="{{ __('store.contact.reach_heading') }}">
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
