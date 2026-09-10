{{--
    The control that opens the mobile menu.

    Different icons need different markup — tiles are four squares, the dot
    icons are dots — so the family decides what is rendered and the chosen
    style supplies the animation.
--}}
@php
    $hd = app(\App\Services\HeaderSettings::class);
    $family = $hd->menuIconFamily();
    $style = $hd->get('menu_icon');
@endphp

{{-- Deliberately not .burger: that class carries the old bar styling, and an
     element wearing both ends up with bar rules fighting tile rules. --}}
<button class="kbbmi kbbmi-{{ $style }}" id="burger" type="button"
        aria-label="Menu" aria-controls="mmenu" aria-expanded="false">
    @switch ($family)
        @case ('tiles')
            <span class="s"></span><span class="s"></span><span class="s"></span><span class="s"></span>
            @break
        @case ('dots9')
            @for ($i = 0; $i < 9; $i++)<span class="d"></span>@endfor
            @break
        @case ('dots3')
            <span class="d"></span><span class="d"></span><span class="d"></span>
            @break
        @default
            <span class="b"></span><span class="b"></span><span class="b"></span>
    @endswitch
</button>
