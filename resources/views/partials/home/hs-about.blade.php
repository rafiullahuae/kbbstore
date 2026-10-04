@php
/*
    Section 9, About us — last on the page.                          (Lane HA)

    Real, indexable text: an H2 and the owner's paragraphs as <p>s, from
    Appearance → Homepage content → About us (`about_text`, a blank line per
    paragraph; HomeSections::ABOUT_DEFAULT is his four, verbatim).

    READ MORE (Lane PF). The owner, 4 October: "i want a read more faded
    functionality which we have on product page … also in mobile." It IS the
    product page's: `.clamp` on the text and a `.readmore` <button> straight
    after it, toggled by tabs.js's toggleReadMore() — the function the product
    description uses — which flips `clamp`/`open` and aria-expanded and
    measures nothing. The WHOLE text is in the page either way, so search
    engines read all four paragraphs; only its height is clamped, in kbb.css,
    with a fade (a mask, so it works on any section background). Appearance →
    Homepage content → About us → Read more turns it off.
*/
@endphp
<section class="sec {{ $ab['classes'] }} {{ $cls }}" style="{{ $ab['style'] }}"@if ($ab['title'] !== '') aria-labelledby="hs-about-h"@endif><div class="wrap"><div class="hs-aboutin" data-readmore>
@include('partials.home.hs-head', ['hid' => 'hs-about-h', 'h' => $ab + ['sub' => '', 'btn' => '', 'url' => '']])
@if ($ab['more'] && $ab['paragraphs'] !== [])
<div class="hs-abtext clamp" id="hs-about-text">
@foreach ($ab['paragraphs'] as $para)
<p>{{ $para }}</p>
@endforeach
</div><button class="readmore hs-abmore" type="button" aria-expanded="false" aria-controls="hs-about-text">{{ __('store.product.read_more') }}</button>
@else
@foreach ($ab['paragraphs'] as $para)
<p>{{ $para }}</p>
@endforeach
@endif
</div></div></section>
