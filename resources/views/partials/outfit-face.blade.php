{{--
    POPPINS, SERVED BY THIS SHOP, FOR A DOCUMENT THAT CARRIES ITS OWN <head>.

    The twin of partials/arabic-face.blade.php, which did this for Cairo and
    left the Latin half undone. Same five documents, same reason, same shape.

    ── WHAT IT REPLACES, AND WHY THAT MATTERS MORE THAN A REQUEST COUNT ──────

    Each caller linked `fonts.googleapis.com/css2?family=Outfit:…` — a
    RENDER-BLOCKING stylesheet on a third-party origin, plus one or two
    preconnects — while layouts/store.blade.php had served the same faces from
    this shop since Lane PERF. App\Support\WebFonts carries that measurement and
    the reason: it is a two-hop round trip (the CSS, then the font) to a host
    this shop does not control.

    IT IS NOT HYPOTHETICAL. In the container this lane works in the browser has
    no route to fonts.googleapis.com, and those four pages rendered with NO
    POPPINS AT ALL — not a near miss, the system face. Measured with two 40px
    rulers, one set in `Outfit` alone and one in a family that cannot exist: on
    the journal, an article, the review wall and the skin quiz the two agreed at
    every weight (436.63px at 400, 463.36px at 600), which is what "the family
    never rendered" looks like as a number. On the pages that self-host, the same
    ruler read 497.20px and 510.17px. That is what a shopper on a network that
    cannot reach Google gets today on those four pages, and the rest of the shop
    stopped having that failure mode when the faces were self-hosted.

    (The single-family ruler is the load-bearing part. A ruler set in
    `Outfit, system-ui, sans-serif` reports the SYSTEM widths wherever Outfit
    is missing, and reports them IDENTICALLY on every such page — which is how
    the first attempt at this measurement produced one plausible number for all
    eight pages and a conclusion drawn from none of them.)

    ── THE WEIGHTS LINE UP NOW, AND THEY DID NOT BEFORE ──────────────────────

    This partial could not have been written last round. WebFonts carried 400,
    600, 700 and 800; the callers asked Google for 500 as well, and the skin quiz
    for 300, so converting them would have silently dropped a weight. 500 is in
    WebFonts now — and its own note has the measurement that says the shop
    needed it anyway, on 106 visible elements. 300 is not, and is not missed:
    the census found ZERO elements at font-weight 300 on seven of the eight
    pages and one invisible one on a product page, and a target of 300 resolves
    to the 400 face regardless, so the file would have changed nothing.

    ── NO ARGUMENTS ─────────────────────────────────────────────────────────

    Unlike partials/arabic-face.blade.php this takes no parameters. Every caller
    wants the same family at the same weights with the same unicode-ranges, and
    a parameter whose only possible value is the default is a way for two
    callers to disagree about something they cannot disagree about.

    ── ZERO CONDITIONS ──────────────────────────────────────────────────────

    It emits unconditionally, because unlike the wash and the accent this is not
    a setting: the page needs a typeface on every render. The file ends at the
    style tag with NO TRAILING NEWLINE so a caller can glue it between the
    markers of its own raw block without introducing whitespace.
--}}{!! \App\Support\WebFonts::preloadTags(\App\Support\WebFonts::OUTFIT) !!}<style id="kbb-outfit">{!! \App\Support\WebFonts::faceCss(\App\Support\WebFonts::OUTFIT) !!}</style>