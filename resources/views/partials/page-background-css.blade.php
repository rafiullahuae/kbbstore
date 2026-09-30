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
  --kbb-page-gradient:linear-gradient(115deg,#FFF1E4 0%,#FFFFFF 38%,#FDECF4 72%,#FCE7F1 100%);
  --kbb-page-gradient-b:linear-gradient(115deg,#FFF1E4 0%,#FFFFFF 49%,#FDECF4 83%,#FCE7F1 100%);
  --kbb-page-gradient-c:linear-gradient(115deg,#FFF1E4 0%,#FFFFFF 60%,#FDECF4 94%,#FCE7F1 100%);
}
body{
  background-color:#FDEFF3;
  background-image:none;
}
html::before,body::before,body::after{
  content:'';display:block;position:fixed;inset:0;pointer-events:none;
}
html::before{z-index:-4;background:var(--kbb-page-gradient)}
body::before{z-index:-2;opacity:0;background:var(--kbb-page-gradient-b)}
body::after{z-index:-1;opacity:0;background:var(--kbb-page-gradient-c)}
@keyframes kbb-page-b{0%{opacity:0}33%{opacity:1}66%{opacity:0}100%{opacity:0}}
@keyframes kbb-page-c{0%{opacity:0}33%{opacity:0}66%{opacity:1}100%{opacity:0}}
@media(prefers-reduced-motion:no-preference){
  body::before{animation:kbb-page-b 300s ease-in-out infinite}
  body::after{animation:kbb-page-c 300s ease-in-out infinite}
}
@media print{
  html::before,body::before,body::after{display:none}
  body{background-color:#fff}
}</style>