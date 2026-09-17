{{--
    Product details tabs — ported from kbb-product.html.

    Two presentations of the same content: a tab bar with a clamped panel and a
    read-more toggle on desktop, and an accordion on mobile. Both are rendered
    server-side so the text is indexable and present without JavaScript; the
    script only handles switching.
--}}
<div class="details" id="details">
    <div class="dtabbar">
        @foreach ($tabs as $i => $tab)
            <button class="dtab{{ 0 === $i ? ' on' : '' }}" type="button" data-i="{{ $i }}">{{ $tab['title'] }}</button>
        @endforeach
    </div>

    <div class="dpanel" id="dpanel">
        @foreach ($tabs as $i => $tab)
            <div class="dtabpanel{{ 0 === $i ? ' on' : '' }}" data-panel="{{ $i }}">
                <div class="dcontent clamp">{!! $tab['body'] !!}</div>
                <button class="readmore" type="button">{{ __('store.product.read_more') }}</button>
            </div>
        @endforeach
    </div>

    {{-- Mobile: the same tabs as an accordion. --}}
    <div class="macc">
        @foreach ($tabs as $i => $tab)
            <div class="macc-i{{ 0 === $i ? ' open' : '' }}">
                <button class="macc-h" type="button" data-i="{{ $i }}">{{ $tab['title'] }}<span class="pm">{{ 0 === $i ? '−' : '+' }}</span></button>
                <div class="macc-b"><div class="dcontent open">{!! $tab['body'] !!}</div></div>
            </div>
        @endforeach
    </div>
</div>
