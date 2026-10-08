{{--
    ONE SLIDE'S TEXT BOX -- Lane HB. $hbW is BannerTextBox::words(), never null
    here; $hbCfg the set's normalised document; $hbBtn the button style that
    draws; $hbAr whether the page is Arabic; $hbRing a document-unique id.

    Every string is printed with {{ }}. The heading's one exception is
    BannerTextBox::headingHtml(), which escapes FIRST and only then turns
    `*words*` into the <mark> it writes itself -- and only for the Sticker card,
    whose highlighter it is; style A gets the words without the asterisks.

    THE BOX COMES BEFORE THE PICTURE'S LINK IN THE SLIDE, and that is
    deliberate: the slider's script gives `tabIndex` to the slide's FIRST
    link, so with a button that is the button, and the picture link -- kept so
    the whole picture is still a click -- carries tabindex="-1" and is never a
    second tab stop for the same place.
--}}<div class="hb-pos{{ $hbW['phone'] ? '' : ' no-m' }}"><div class="hb-box{{ $hbW['end'] ? ' is-end' : '' }}">@if ($hbW['sticker'] !== '')<span class="hb-stk">@if ($hbW['ring'] !== '')<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"><path id="{{ $hbRing }}" d="M50,50 m-38,0 a38,38 0 1,1 76,0 a38,38 0 1,1 -76,0" fill="none"/><text><textPath href="#{{ $hbRing }}"@unless ($hbAr) textLength="236" lengthAdjust="spacing"@endunless>{{ $hbW['ring'] }} ✦ {{ $hbW['ring'] }} ✦ </textPath></text></svg>@endif<b>{{ $hbW['sticker'] }}</b></span>@endif
@if ($hbW['eyebrow'] !== '')<p class="hb-eb">{{ $hbW['eyebrow'] }}</p>@endif
@if ($hbW['heading'] !== '')<h2 class="hb-h">{!! $hbCfg['style'] === 'd' ? \App\Support\BannerTextBox::headingHtml($hbW['heading']) : e(\App\Support\BannerTextBox::headingText($hbW['heading'])) !!}</h2>@endif
@if ($hbW['text'] !== '')<p class="hb-t">{{ $hbW['text'] }}</p>@endif
@if ($hbW['button'] !== '')<a class="hb-btn hb-{{ $hbBtn }}" href="{{ \App\Support\Url::to($hbW['href']) }}" draggable="false">{{ $hbW['button'] }}@if ($hbBtn !== 'under')<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif</a>@endif
</div></div>
