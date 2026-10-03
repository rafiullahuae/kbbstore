{{--
    The site footer: which of the two designs this shop draws.       (Lane HB)

    Appearance → Footer → "Site footer · design". `bliss` is the approved
    design (docs/home-preview/footer-final.html) and is what ships, because the
    owner asked for it; `classic` is the footer the shop had before, kept byte
    for byte in partials/footer-classic so he can go back. SiteFooter::design()
    answers one of those two literals, never the stored string.

    The comment ends glued to the @include on purpose: a Blade comment becomes
    the empty string and its trailing newline survives, so on a line of its own
    it would put one extra newline in front of the classic footer and that
    design would no longer be byte-identical to what it was.
--}}@include(app(\App\Services\SiteFooter::class)->design() === 'classic' ? 'partials.footer-classic' : 'partials.footer-bliss')
