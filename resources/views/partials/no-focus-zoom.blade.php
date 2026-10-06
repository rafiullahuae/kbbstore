{{--
    NO PAGE ZOOM WHEN A FIELD TAKES FOCUS, ON ANY TOUCH SCREEN (Lane ZM).

    For the documents that carry their own inline stylesheet instead of kbb.css:
    the skin quiz, the /app preview, the admin console and its standalone
    screens. The storefront's copy is the last block of kbb.css and the owner
    app's the last of owner-app.css (its CSP forbids an inline <style>); all of
    them are the same rule, and NoFocusZoomTest holds each one to it.

    iOS Safari zooms the page in when a field under 16px takes focus and does
    not zoom back out. The floor is by element and !important so no class rule
    or inline style can undercut it, and only on a screen whose main input is a
    finger, so a laptop resolves exactly what it did. A field drawn LARGER than
    16px is re-asserted by its own document, inside the same query, with its
    own !important -- :where() gives the floor zero specificity so that
    re-assertion wins (bare, the nine :not() tests outrank any class rule).
--}}<style>@media (hover:none),(pointer:coarse){:where(input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]),select,textarea,[contenteditable]:not([contenteditable=false])){font-size:16px!important}}</style>