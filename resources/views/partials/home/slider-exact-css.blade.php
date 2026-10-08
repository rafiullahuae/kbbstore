{{--
    "EXACTLY THIS HEIGHT" -- Lane HB3. Included by slider-banner.blade.php only
    when a device's height mode is `exact`, so every other slider serves the
    bytes it served before. The frame drops its aspect-ratio and IS the height
    the server printed as --kbbs-hd / --kbbs-hm (an integer in px, clamped by
    BannerSet::sliderHeight()), known from CSS before any picture arrives -- no
    layout shift. The picture covers it, centred: a whole picture in a
    fixed-height frame would be letterboxed or stretched, and there is no
    focal point stored to aim a crop at.
--}}<style>@if ($bsExactM)@media (max-width:767.98px){.kbbs.is-hx-m .kbbs-vp{aspect-ratio:auto;height:var(--kbbs-hm)}.kbbs.is-hx-m .kbbs-a img{object-fit:cover;object-position:50% 50%}}@endif
@if ($bsExactD)@media (min-width:768px){.kbbs.is-hx-d .kbbs-vp{aspect-ratio:auto;height:var(--kbbs-hd)}.kbbs.is-hx-d .kbbs-a img{object-fit:cover;object-position:50% 50%}}@endif
</style>
