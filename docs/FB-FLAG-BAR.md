# The flag bar

> "i need thin bar as same as attached, having uae flat, then text and then
> korea flag. (This bar is only for mobile, keep this turnef off for desktop by
> default)."

A thin strip above the header: the **UAE flag**, one short line in a rounded
outline, and the **South Korean flag**.

**Where it is set: Appearance → Header → Flag bar.**

## What applying the package does

On a phone, a 30px strip appears at the top of every page that carries the site
header. **On the desktop, nothing moves at all** — the strip is in the markup
and `display:none` above 900px, which is the breakpoint this stylesheet already
uses everywhere else.

That is the only new default in this package that is not "whatever the page does
today", and it is there because the sentence above asked for it in those words.
Everything else — the wording, the colours, the sizes, whether the flags are
drawn at all — ships at a value chosen to look like the picture, and moving any
of it is one slider on one screen.

## The eleven controls

| Control | Ships | What it does |
| --- | --- | --- |
| Show it on phones | **on** | the strip below 900px |
| Show it on desktop | **off** | the same strip above 900px |
| Wording | *(empty)* | empty means the line the shop ships, which is translated |
| Show the two flags | on | both flags, or neither |
| Bar height | 30px | reserved in the stylesheet, so nothing below it jumps |
| Text size | 12px | |
| Flag height | 14px | the width follows; both flags are drawn 3:2 |
| Background | `#FDEFF4` | |
| Text colour | `#E0567B` | the shop's own pink |
| Outline around the words | on | the rounded border the line sits in |
| Outline colour | `#F0B6C9` | |

Switching **both** visibility controls off removes the strip from the document
entirely — not a hidden element, no element — and the page goes back to being
byte-for-byte what it was before the package.

## Things worth knowing

**The wording is translated until you type your own.** Empty means
`store.flagbar.text`, so an Arabic page shows Arabic (once the draft is
approved under Translation → Strings). Typing your own replaces it in every
language, which is the same trade every other box you type into on this shop
makes.

**The flags are drawings, not emoji.** `🇦🇪` and `🇰🇷` are not characters — they
are regional-indicator pairs, and every browser on Windows draws them as the
boxed letters `AE` and `KR`. They are inline SVG in `App\Support\FlagArt`, drawn
to each flag's own specification, and each carries a name a screen reader reads.

**On `/ar` the pair swaps sides.** The UAE flag sits at the reading start in
both languages — left in English, right in Arabic — and so does everything else
on the strip. That comes out of the markup order and logical CSS properties;
there is no second rule to keep in step.

**It costs no query.** The eleven settings live inside `header_settings`, the
same single row the header itself already reads on every page.

## For the integrator

**Nothing to wire.** No route file, no admin partial, no edit to
`routes/web.php` and none to `resources/views/admin/app.blade.php`. Appearance →
Header is drawn generically from `HeaderSettings::TABS`, so the tab arrives with
the constant.

Two migrations ship with it:

* `2027_05_10_000000_clear_caches_flag_bar` — a new Blade partial, a changed
  layout and a new stylesheet hash. The compiled views on this host outlive the
  files they came from.
* `2027_05_10_000100_seed_flag_bar_arabic_drafts` — the three `store.flagbar.*`
  Arabic drafts. A second migration because `2027_04_28_000000` has already run
  on this shop and a migration that has run does not run again.

`public/build` is rebuilt and committed (`npx vite build`), because `kbb.css`
gained the strip's rules and CI does not build assets.

`StorefrontEnglishUnchangedTest` stays pinned where it was. The strip is
approved through `EnglishRenderWalk::approvedInsertions()` instead — 31 pages,
counted — so the walk goes on comparing every other byte of every page for every
other lane. See that method's own header for why a `BASE_COMMIT` move was the
wrong answer here.

Pictures and measurements: `docs/lane-fb-shots/README.md`.
