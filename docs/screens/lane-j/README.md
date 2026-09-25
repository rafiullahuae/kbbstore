# Content → Blog Posts → New article — the pictures, and the numbers (Lane J)

Chromium 1194, `deviceScaleFactor: 2`, full-page, against a real HTTP server
with a real database — not a mock. The harness is
`tools/laneq-shot-admin.cjs`'s pattern; every figure below was read back off
the live page in the same run that took the shot.

`document.documentElement.scrollWidth` equals the viewport on **every** shot at
both widths, so nothing on this screen scrolls sideways:

| shot | 1280 | 390 |
|---|---|---|
| Blog Posts, empty | 1280 / 1280 | 390 / 390 |
| New article, blank | 1280 / 1280 | 390 / 390 |
| the reserved-slug refusal | 1280 / 1280 | 390 / 390 |
| a free address | 1280 / 1280 | 390 / 390 |
| written, with its Arabic | 1280 / 1280 | 390 / 390 |
| saved | 1280 / 1280 | — |
| Blog Posts, with the article | 1280 / 1280 | 390 / 390 |
| the article on the shop | 1280 / 1280 | 390 / 390 |
| the article in Arabic | 1280 / 1280 | 390 / 390 |
| /skincare-guide/ | 1280 / 1280 | 390 / 390 |

(`viewport / scrollWidth`. The two-column form collapses to one at 980px with a
media query; nothing in the screen's script reads a width — CLAUDE.md rule 4.)

## What the refusal actually said, read off the element

`#pj-addr`, `className: "pj-addr is-bad"`, `font-size: 12px`, at both widths,
typed live as the owner typed the word "Wishlist" into Title:

> “/wishlist/” already belongs to the shop, so an article published there could
> never be opened — the storefront answers that address. Choose a different slug.

And the same box, on a free address:

> Will be published at **/heartleaf-and-what-it-actually-does/** — tidied from
> what you typed, so it is an address the shop can serve.

And on a collision, with the way out:

> Another article is already published at “/heartleaf-and-what-it-actually-does/”.
> Try **heartleaf-and-what-it-actually-does-2**.

## The save, and what reached the shop

The message strip after pressing Save: *"Article created at
/heartleaf-and-what-it-actually-does/."* The article is then served at that
address (08), and at `/ar/heartleaf-and-what-it-actually-does/` with the Arabic
title, excerpt and body typed into the boxes beside the English ones (09) — one
slug, the language carried by the `/ar` prefix.

## One real defect these pictures caught and the suite had not

Clearing the **Author** box and pressing Save answered a bare
`SQLSTATE[23000]: NOT NULL constraint failed: posts.author`, printed into the
message strip, after the whole article had been written and with nothing saved.
`posts.author` is NOT NULL with a column default; every test had supplied an
author, so the suite was green over it. Fixed, and pinned two ways in
`tests/Feature/JournalArticleEditorTest` — the empty box, and the constant
against the migration's own default.
