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
<section class="sec {{ $ft['classes'] }} {{ $cls }}" style="{{ $ft['style'] }}"><div class="wrap"><div class="hs-feat">
@foreach ($ft['panels'] as $panel)
{{-- A NAME FOR THE PICTURE LINK WHEN THE PICTURE GIVES IT NONE (Lane PS).
     PageSpeed, 5 Oct 2026, Accessibility: "Links do not have a discernible
     name" and "Accessibility tree is not well-formed", both naming
     `div.hs-fp > a.hs-fim` -- the panel with no photo chosen is an empty link
     painted with a gradient. It is named by the panel's own title (or its
     button's words), which sit right under it; with neither it leaves the
     accessibility tree, since it is out of the tab order already. An
     attribute, so nothing on the page moves. --}}<div class="hs-fp"><a class="hs-fim" href="{{ $panel['url'] }}" tabindex="-1"@if (($fimName = $panel['image'] !== '' && $panel['alt'] !== '' ? null : ($panel['title'] !== '' ? $panel['title'] : $panel['btn'])) === null)@elseif ($fimName !== '') aria-label="{{ $fimName }}"@else aria-hidden="true"@endif style="background:{{ \App\Support\Gradient::for($panel['title'] !== '' ? $panel['title'] : $panel['side']) }}">@if ($panel['image'] !== '')<img src="{{ $panel['image'] }}" alt="{{ $panel['alt'] }}" width="800" height="450" loading="lazy" decoding="async">@endif</a>
@if ($panel['title'] !== '')<h2>{{ $panel['title'] }}</h2>@endif
@if ($panel['text'] !== '')<p>{{ $panel['text'] }}</p>@endif
@if ($panel['btn'] !== '')<a class="hs-go" href="{{ $panel['url'] }}">{{ $panel['btn'] }} <b aria-hidden="true">▸</b></a>@endif</div>
@endforeach
</div></div></section>
