@php
/*
    Section 9, About us — last on the page.                          (Lane HA)

    Real, indexable text: an H2 and the owner's paragraphs as <p>s, from
    Appearance → Homepage content → About us (`about_text`, a blank line per
    paragraph; HomeSections::ABOUT_DEFAULT is his four, verbatim).
*/
@endphp
<section class="sec {{ $ab['classes'] }} {{ $sections->classFor('about') }}" style="{{ $ab['style'] }}"@if ($ab['title'] !== '') aria-labelledby="hs-about-h"@endif><div class="wrap"><div class="hs-aboutin">
@include('partials.home.hs-head', ['hid' => 'hs-about-h', 'h' => $ab + ['sub' => '', 'btn' => '', 'url' => '']])
@foreach ($ab['paragraphs'] as $para)
<p>{{ $para }}</p>
@endforeach
</div></div></section>
