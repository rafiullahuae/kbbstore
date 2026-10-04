@php
/*
    One section heading for the row-55 homepage sections.           (Lane HA)

    $hid  the H2's id (the section is aria-labelledby it)
    $h    ['eyebrow', 'title', 'sub', 'btn', 'url'] — App\Support\HomeSections,
          already trimmed to plain text and, for the URL, scheme-checked.

    Every word is the owner's and printed through {{ }}. The button is the
    shop's global pill (`.bndl-all`, the redesigned All sets button). With
    `place => top` it sits beside the heading on a laptop and under the
    section on a phone; otherwise under the section on both (`.hs-foot`).
*/
@endphp
@php $hsTop = ($h['place'] ?? 'bottom') === 'top' && ($h['btn'] ?? '') !== '' && ($h['url'] ?? '') !== ''; @endphp
<div class="hs-head{{ $hsTop ? ' hs-split' : '' }}{{ $hsTop && ($h['center'] ?? false) ? ' hs-split-c' : '' }}"><div class="hs-ht">@if (($h['eyebrow'] ?? '') !== '')<span class="hs-eyebrow">{{ $h['eyebrow'] }}</span>@endif
@if ($h['title'] !== '')<h2 id="{{ $hid }}">{{ $h['title'] }}</h2>@endif
@if (($h['sub'] ?? '') !== '')<p>{{ $h['sub'] }}</p>@endif</div>@if ($hsTop)<a class="bndl-all hs-btn hs-btn-top" href="{{ $h['url'] }}">{{ $h['btn'] }}<i>{!! \App\Support\HomeSections::ARROW !!}</i></a>@endif</div>
