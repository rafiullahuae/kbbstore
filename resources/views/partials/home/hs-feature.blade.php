@php
/*
    Section 8, the two-column feature.                               (Lane HA)

    Each panel: a photo (chosen from the Media Library on Appearance →
    Homepage content → Two-column feature), a title, a thin rule, a line of
    text and SHOP NOW ▸. Left → the Sunscreens category, right →
    /best-sellers, both editable and scheme-checked by HomeSections::url().
    The titles are the section's H2s. No photo chosen draws a soft panel of the
    same shape, so nothing moves when one is added.
*/
@endphp
<section class="sec {{ $ft['classes'] }} {{ $sections->classFor('feature') }}" style="{{ $ft['style'] }}"><div class="wrap"><div class="hs-feat">
@foreach ($ft['panels'] as $panel)
<div class="hs-fp"><a class="hs-fim" href="{{ $panel['url'] }}" tabindex="-1" style="background:{{ \App\Support\Gradient::for($panel['title'] !== '' ? $panel['title'] : $panel['side']) }}">@if ($panel['image'] !== '')<img src="{{ $panel['image'] }}" alt="{{ $panel['alt'] }}" width="800" height="450" loading="lazy" decoding="async">@endif</a>
@if ($panel['title'] !== '')<h2>{{ $panel['title'] }}</h2>@endif
@if ($panel['text'] !== '')<p>{{ $panel['text'] }}</p>@endif
@if ($panel['btn'] !== '')<a class="hs-go" href="{{ $panel['url'] }}">{{ $panel['btn'] }} <b aria-hidden="true">▸</b></a>@endif</div>
@endforeach
</div></div></section>
