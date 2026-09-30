{{--
    THE SHOP'S PAGE BACKGROUND, FOR THE TWO DOCUMENTS THAT CARRY THEIR OWN HEAD.
                                                                      (Lane BG)

    The journal (/skincare-guide/) and an article do not extend
    layouts/store.blade.php and so never load kbb.css, where the page
    background lives. Without this include they are the only two storefront
    pages still wearing whatever the last one looked like.

    ── IT IS 337 BYTES NOW, AND IT WAS 9,485 ────────────────────────────────

    It used to carry `--bg-botanical` in full: 9,130 bytes of URL-encoded SVG,
    copied here because kbb.css could not be reached from these documents. The
    owner has since said he does not want that drawing on the background of the
    site at all, so it is out of the page background everywhere -- and this file
    lost nine kilobytes with it. Measured: +9,461 raw / +1,093 gzipped per page
    view before, +337 raw / ~210 gzipped now.

    ── IT MIRRORS kbb.css, AND A TEST HOLDS THE TWO TOGETHER ────────────────

    The declaration and the body rule below are the same two the layout gets
    from kbb.css. They are duplicated rather than linked because moving them
    into a separate stylesheet would add a render-blocking request to the forty
    pages that already fetch kbb.css -- costed in
    docs/BG-STANDALONE-DOCUMENTS.md §5. StandaloneDocumentHeadTest requires this
    file to carry kbb.css's `--kbb-page-gradient` declaration byte for byte, so
    the copy cannot drift: change one and the other goes red, naming both.

    ── WHERE IT GOES IN A DOCUMENT, AND IT IS NOT LAST ──────────────────────

    AFTER that document's own <style>, because the rule it has to beat is that
    stylesheet's `body{background:var(--bg)}` and both are `body` -- they tie on
    specificity and source order decides.

    BEFORE partials/page-wash-css.blade.php. The wash is the owner's own choice
    and has to win, which it only does by coming later. That is the same order
    layouts/store.blade.php has: kbb.css first, the wash last.

    THE FILE ENDS AT THE STYLE TAG WITH NO TRAILING NEWLINE, so a caller can
    glue the include between the markers of its own raw block without adding a
    blank line to the head of either document.
--}}<style id="kbb-page-background">:root{
  --kbb-page-gradient:linear-gradient(180deg,#FCE7EE 0%,#FDF2F5 26%,#FFF7F4 55%,#FBEAF0 100%);
}
body{
  background-color:#FDEFF3;
  background-image:var(--kbb-page-gradient);
  background-size:auto;
  background-position:0 0;
  background-repeat:no-repeat;
  background-attachment:fixed;
}</style>