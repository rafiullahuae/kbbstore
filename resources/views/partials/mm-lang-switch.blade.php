{{--
    The language switch at the top of the phone menu (17 October 2026).

    The owner: "i need the switch in menu panel too, as previewed" -- round 1's
    option G (docs/lane-hl/previews/G-390-menu.png): "English | العربية" as one
    soft capsule, the current language raised in white, beside the menu's ×.

    Same pair and addresses as the header and footer switches
    (App\Support\LangSwitch::links(), asked once per request). Shown while the
    Arabic shop is on and Appearance → Header → Icons → Language switch is
    Phone or Both -- the menu is the phone's. With Arabic off it prints
    NOTHING, not a byte, so the English shop is what it was. Every piece is a
    constant or e()-escaped.
--}}@php
    $kbbMlsHtml = '';
    if (in_array((string) app(\App\Services\HeaderSettings::class)->get('lang_switch'), ['phone', 'both'], true)) {
        $kbbMlsHere = \App\Support\Locale::current();
        $kbbMlsLinks = \App\Support\LangSwitch::links();
        if (isset($kbbMlsLinks[$kbbMlsHere])) {
            foreach ($kbbMlsLinks as $kbbMlsCode => $kbbMlsHref) {
                $kbbMlsHtml .= $kbbMlsCode === $kbbMlsHere
                    ? '<span lang="' . e($kbbMlsCode) . '" aria-current="true">' . e(\App\Support\LangSwitch::NAMES[$kbbMlsCode]) . '</span>'
                    : '<a href="' . e($kbbMlsHref) . '" lang="' . e($kbbMlsCode) . '" hreflang="' . e($kbbMlsCode) . '">' . e(\App\Support\LangSwitch::NAMES[$kbbMlsCode]) . '</a>';
            }
        }
    }
@endphp{{-- every piece above is e()-escaped; the names are constants --}}@if ($kbbMlsHtml !== '')<div class="mm-lang">{!! $kbbMlsHtml !!}</div>@endif