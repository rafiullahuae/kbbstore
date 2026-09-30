{{--
    THE SHOP'S DESIGNED PAGE BACKGROUND, FOR THE TWO DOCUMENTS THAT WERE WHITE.
                                                                      (Lane BG)

    The journal (/skincare-guide/) and an article are the last two storefront
    pages that do not carry the background every other page has. They are white
    because they do not extend layouts/store.blade.php and therefore never load
    kbb.css, where the designed rule lives — the same structural hole that kept
    the brand colour, the site width and the Latin webfont off these documents,
    and the last part of it still open.

    ── THREE WAYS TO CLOSE IT, COSTED RATHER THAN ARGUED ────────────────────

    Measured on the running preview, gzip -9, which is what "bytes on the wire"
    means for a document this server compresses:

      1. LOAD kbb.css ON BOTH DOCUMENTS.
         179,853 bytes built, 30,673 gzipped, plus one render-blocking request.
         28x the gzipped cost of option 3 for one body rule, and kbb.css also
         carries base rules for `body`, headings and links that would land on
         top of each document's own inline stylesheet. That is a layout change
         to two working pages to deliver a background.

      2. COPY THE GRADIENT AS A DATA URI INTO EACH DOCUMENT.
         What round 2 costed at "~40 KB" and did not do. The estimate was 4x
         high: the artwork is 9,130 bytes of URL-encoded SVG, not 40,000. But
         copied into each document it is two copies that drift apart silently.

      3. ONE PARTIAL, INCLUDED INLINE BY BOTH — this file.
         +9,461 bytes raw and +1,093 GZIPPED per page view, measured by
         splicing it into the served /blog/ document and compressing both.
         ZERO extra requests, nothing render-blocking, and it paints with first
         paint because it is already in the head when the parser reaches body.

    Option 3, and the reason is the request rather than the bytes: options 1
    and 2 are the same picture as 3, and 1 adds a round trip to two pages that
    currently make none for CSS.

    ── WHY kbb.css KEEPS ITS OWN COPY, WHICH LOOKS LIKE THE WRONG CHOICE ────

    The obvious form of option 3 is "extract it from kbb.css so nothing is
    copied, and have the layout and both documents link it". Costed, and it is
    a REGRESSION for the forty pages that work: those pages already fetch
    kbb.css, so moving 1.1 KB of it into a separate file adds a second
    render-blocking request to every one of them and takes back part of the
    4,369 ms critical path Lane PERF cut. Inlining it into the layout instead
    moves the bytes out of a cacheable stylesheet and into uncacheable HTML on
    every page, and moves the designed rule 1,400 lines earlier in the cascade,
    where two other `body` rules in kbb.css are waiting for it.

    So kbb.css is NOT TOUCHED — the working shop renders byte-identically — and
    the duplication is held shut MECHANICALLY instead of by good intentions:
    StandaloneDocumentHeadTest extracts the declaration from kbb.css and
    requires this file to carry it byte for byte. Change one byte of the data
    URI in EITHER file and it goes red naming both, which is the whole reason a
    copy is tolerable here. (OnePageBackgroundTest is the neighbouring guard and
    asserts the other half: that exactly ONE rule in the three shared sheets
    gives `body` a background, and that the review wall and the skin quiz keep
    their own compositions.)

    ── WHERE IT GOES IN A DOCUMENT, AND IT IS NOT LAST ──────────────────────

    AFTER that document's own <style>, because the rule it has to beat is that
    stylesheet's `body{background:var(--bg)}` and both are `body` — they tie on
    specificity and source order decides.

    BEFORE partials/page-wash-css.blade.php, which is the opposite of what the
    accent and the wash both ask for, and deliberate. The wash is the OWNER'S
    choice from Appearance → Page background; this is the shipped design. When
    the owner switches a wash on it must win, and it only wins if it comes
    later. That is the same order layouts/store.blade.php has: kbb.css first,
    the wash partial last.

    NO CONDITION AND NO SETTING. Unlike the wash and the accent this emits the
    same bytes on every render, so unlike them it is not free: it is a visible
    change to two pages, which is the change that was asked for. Every selector,
    property and byte below is a literal — there is no PHP in this file and no
    saved value can reach it.

    THE FILE ENDS AT THE STYLE TAG WITH NO TRAILING NEWLINE, so a caller can
    glue the include between the markers of its own raw block without adding a
    blank line to the head of either document.
--}}<style id="kbb-page-background">:root{
  --bg-botanical:url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%201200%20900%27%20preserveAspectRatio%3D%27xMidYMid%20slice%27%3E%3Cg%20transform%3D%27translate%28-70%2C150%29%20rotate%28-14%29%20scale%282.4%29%27%20opacity%3D%270.3%27%3E%3Cpath%20d%3D%27M0%2C0%20C40%2C-36%2092%2C-30%20112%2C5%20C86%2C42%2034%2C42%200%2C0%20Z%27%20fill%3D%27%23EFA9BC%27%2F%3E%3Cpath%20d%3D%27M0%2C0%20C36%2C3%2078%2C7%20112%2C5%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.55%29%27%20stroke-width%3D%272.5%27%20fill%3D%27none%27%2F%3E%3Cpath%20d%3D%27M28%2C-14%20C40%2C-6%2052%2C-2%2066%2C0%20M46%2C-20%20C58%2C-11%2070%2C-6%2084%2C-3%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.32%29%27%20stroke-width%3D%271.6%27%20fill%3D%27none%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%281120%2C90%29%20rotate%28168%29%20scale%282.6%29%27%20opacity%3D%270.26%27%3E%3Cpath%20d%3D%27M0%2C0%20C40%2C-36%2092%2C-30%20112%2C5%20C86%2C42%2034%2C42%200%2C0%20Z%27%20fill%3D%27%23B9D6C1%27%2F%3E%3Cpath%20d%3D%27M0%2C0%20C36%2C3%2078%2C7%20112%2C5%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.55%29%27%20stroke-width%3D%272.5%27%20fill%3D%27none%27%2F%3E%3Cpath%20d%3D%27M28%2C-14%20C40%2C-6%2052%2C-2%2066%2C0%20M46%2C-20%20C58%2C-11%2070%2C-6%2084%2C-3%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.32%29%27%20stroke-width%3D%271.6%27%20fill%3D%27none%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%2860%2C760%29%20rotate%28-158%29%20scale%282.1%29%27%20opacity%3D%270.24%27%3E%3Cpath%20d%3D%27M0%2C0%20C40%2C-36%2092%2C-30%20112%2C5%20C86%2C42%2034%2C42%200%2C0%20Z%27%20fill%3D%27%23F2C6A5%27%2F%3E%3Cpath%20d%3D%27M0%2C0%20C36%2C3%2078%2C7%20112%2C5%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.55%29%27%20stroke-width%3D%272.5%27%20fill%3D%27none%27%2F%3E%3Cpath%20d%3D%27M28%2C-14%20C40%2C-6%2052%2C-2%2066%2C0%20M46%2C-20%20C58%2C-11%2070%2C-6%2084%2C-3%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.32%29%27%20stroke-width%3D%271.6%27%20fill%3D%27none%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%281080%2C780%29%20rotate%2824%29%20scale%282.3%29%27%20opacity%3D%270.26%27%3E%3Cpath%20d%3D%27M0%2C0%20C40%2C-36%2092%2C-30%20112%2C5%20C86%2C42%2034%2C42%200%2C0%20Z%27%20fill%3D%27%23CDB4E4%27%2F%3E%3Cpath%20d%3D%27M0%2C0%20C36%2C3%2078%2C7%20112%2C5%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.55%29%27%20stroke-width%3D%272.5%27%20fill%3D%27none%27%2F%3E%3Cpath%20d%3D%27M28%2C-14%20C40%2C-6%2052%2C-2%2066%2C0%20M46%2C-20%20C58%2C-11%2070%2C-6%2084%2C-3%27%20stroke%3D%27rgba%28255%2C255%2C255%2C.32%29%27%20stroke-width%3D%271.6%27%20fill%3D%27none%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%28150%2C900%29%20rotate%28-8%29%20scale%281.5%29%27%20opacity%3D%270.3%27%20stroke%3D%27%23E8A7B8%27%20fill%3D%27none%27%20stroke-width%3D%273%27%20stroke-linecap%3D%27round%27%3E%3Cpath%20d%3D%27M0%2C0%20C20%2C-40%2040%2C-80%2044%2C-130%27%2F%3E%3Cellipse%20cx%3D%2714%27%20cy%3D%27-30%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23E8A7B8%27%20stroke%3D%27none%27%20transform%3D%27rotate%28-26%2014%20-30%29%27%2F%3E%3Cellipse%20cx%3D%27-8%27%20cy%3D%27-46%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23E8A7B8%27%20stroke%3D%27none%27%20transform%3D%27rotate%2824%20-8%20-46%29%27%2F%3E%3Cellipse%20cx%3D%2726%27%20cy%3D%27-64%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23E8A7B8%27%20stroke%3D%27none%27%20transform%3D%27rotate%28-30%2026%20-64%29%27%2F%3E%3Cellipse%20cx%3D%272%27%20cy%3D%27-84%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23E8A7B8%27%20stroke%3D%27none%27%20transform%3D%27rotate%2820%202%20-84%29%27%2F%3E%3Cellipse%20cx%3D%2734%27%20cy%3D%27-100%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23E8A7B8%27%20stroke%3D%27none%27%20transform%3D%27rotate%28-34%2034%20-100%29%27%2F%3E%3Cellipse%20cx%3D%2712%27%20cy%3D%27-118%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23E8A7B8%27%20stroke%3D%27none%27%20transform%3D%27rotate%2818%2012%20-118%29%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%281060%2C900%29%20rotate%2810%29%20scale%281.4%29%27%20opacity%3D%270.26%27%20stroke%3D%27%23A9CBB4%27%20fill%3D%27none%27%20stroke-width%3D%273%27%20stroke-linecap%3D%27round%27%3E%3Cpath%20d%3D%27M0%2C0%20C20%2C-40%2040%2C-80%2044%2C-130%27%2F%3E%3Cellipse%20cx%3D%2714%27%20cy%3D%27-30%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23A9CBB4%27%20stroke%3D%27none%27%20transform%3D%27rotate%28-26%2014%20-30%29%27%2F%3E%3Cellipse%20cx%3D%27-8%27%20cy%3D%27-46%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23A9CBB4%27%20stroke%3D%27none%27%20transform%3D%27rotate%2824%20-8%20-46%29%27%2F%3E%3Cellipse%20cx%3D%2726%27%20cy%3D%27-64%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23A9CBB4%27%20stroke%3D%27none%27%20transform%3D%27rotate%28-30%2026%20-64%29%27%2F%3E%3Cellipse%20cx%3D%272%27%20cy%3D%27-84%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23A9CBB4%27%20stroke%3D%27none%27%20transform%3D%27rotate%2820%202%20-84%29%27%2F%3E%3Cellipse%20cx%3D%2734%27%20cy%3D%27-100%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23A9CBB4%27%20stroke%3D%27none%27%20transform%3D%27rotate%28-34%2034%20-100%29%27%2F%3E%3Cellipse%20cx%3D%2712%27%20cy%3D%27-118%27%20rx%3D%2713%27%20ry%3D%277%27%20fill%3D%27%23A9CBB4%27%20stroke%3D%27none%27%20transform%3D%27rotate%2818%2012%20-118%29%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%28240%2C300%29%20scale%281.5%29%27%20opacity%3D%270.22%27%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%280%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%2845%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%2890%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%28135%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%28180%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%28225%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%28270%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23F7C6D4%27%20transform%3D%27rotate%28315%29%27%2F%3E%3Ccircle%20r%3D%2713%27%20fill%3D%27%23EFAF6B%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%28980%2C520%29%20scale%281.2%29%27%20opacity%3D%270.2%27%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%280%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%2845%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%2890%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%28135%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%28180%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%28225%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%28270%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23DCC7EE%27%20transform%3D%27rotate%28315%29%27%2F%3E%3Ccircle%20r%3D%2713%27%20fill%3D%27%23F0B9C8%27%2F%3E%3C%2Fg%3E%3Cg%20transform%3D%27translate%28620%2C120%29%20scale%280.9%29%27%20opacity%3D%270.16%27%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%280%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%2845%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%2890%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%28135%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%28180%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%28225%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%28270%29%27%2F%3E%3Cellipse%20cx%3D%270%27%20cy%3D%27-32%27%20rx%3D%2717%27%20ry%3D%2734%27%20fill%3D%27%23FBD9E1%27%20transform%3D%27rotate%28315%29%27%2F%3E%3Ccircle%20r%3D%2713%27%20fill%3D%27%23EFAF6B%27%2F%3E%3C%2Fg%3E%3C%2Fsvg%3E");
}
body{
  background-color:#FDEFF3;
  background-image:var(--bg-botanical),
    linear-gradient(180deg,#FCE7EE 0%,#FDF2F5 26%,#FFF7F4 55%,#FBEAF0 100%);
  background-size:1500px auto,auto;
  background-position:center top,0 0;
  background-repeat:repeat-y,no-repeat;
  background-attachment:fixed,fixed;
}</style>