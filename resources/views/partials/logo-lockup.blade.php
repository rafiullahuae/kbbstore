{{--
    The lotus lockup, logo option D "Pearl" (Lane LG2). Printed by the header,
    the slim header, the mobile drawer and the checkout's own header whenever
    Appearance → Header → Logo → Logo style is "Lotus lockup".

    $el is 'a' (a link home, $href) or 'div' (the drawer's, which is not a link
    and carries no shine: the drawer sits off-screen until it is opened, and
    an animation nobody can see is still an animation).

    $art is how the icon is drawn — 'def' (the site header, which names it),
    'use' (the drawer, which points at the header's) or 'full' (the default).
    See LogoLockup::svg().

    Escaped everywhere except the artwork, which is a constant in
    App\Support\LogoLockup. `style` is custom properties of numbers and strict
    hex colours only, and is empty at the shipped values — see
    HeaderSettings::lockup(). No <span> inside: `.logo span` and
    `header .logo span` colour and size every span under a `.logo` in four
    stylesheets.

    ONE LINE OF OUTPUT. Every source line below ends in a directive, whose
    closing `?>` swallows the newline after it, and the file's last line is
    the one newline the caller's own line had. The comment shares its last
    line with the PHP block for the same reason. (Not "@endif@if" on one line: Blade
    only sees a directive after a non-word character, so that is one word.)
--}}@php $lg = app(\App\Services\HeaderSettings::class)->lockup(); $div = ($el ?? 'a') === 'div'; @endphp
@if ($div)
<div class="{{ $lg['class'] }}"@if ($lg['style'] !== '') style="{{ $lg['style'] }}"@endif>@else
<a class="{{ $lg['class'] }}" href="{{ $href }}" aria-label="{{ $lg['label'] }}"@if ($lg['style'] !== '') style="{{ $lg['style'] }}"@endif>@endif
@if ($lg['shine'] && ! $div)
<i class="lgx-lt" aria-hidden="true"></i>@endif
{!! \App\Support\LogoLockup::svg($lg['glow'], $art ?? 'full') !!}<b class="lgx-t"><bdi class="lgx-w">{{ $lg['name'] }}<em>{{ $lg['accent'] }}</em></bdi>@if ($lg['tag'] !== '')
<small class="lgx-g">{{ $lg['tag'] }}</small>@endif
</b>@if ($div)
</div>@else
</a>@endif

