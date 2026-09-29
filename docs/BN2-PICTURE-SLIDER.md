# The picture slider — the second banner type

Phase 22 round 8, Lane BN2. The owner, in full:

> I want another banner type with only images slider with proper beautiful left
> right arrows. and thin bars the bottom of the banner to control all sliders.
> give me some nice previews to chooose from.

## Where it sits in the admin

**Appearance → Banners → (a set) → Banner type → What this set is → “Slider —
pictures only, one at a time, with arrows and bars”.**

To build one from scratch: **Appearance → Banners → Your banner sets → New
picture slider**, then **Appearance → Banners → The cards banner on the homepage
→ Which set shows** to put it on the front page. A set is a **draft** when it is
created and a draft never shows on the shop.

The four looks are **Appearance → Banners → (a set) → Look → Where the arrows and
bars sit**. The sentence under the picker changes with the choice, and the live
preview at the bottom of the editor redraws as you go — including the arrows and
the bars, which really move in it.

## The four looks

Pictures: `docs/lane-bn2-shots/contact-sheet-390.png` and
`docs/lane-bn2-shots/contact-sheet-1280.png`, one tile per look at each width.

| Token | Name | Arrows | Bars | Bars sit |
|---|---|---|---|---|
| `inset` | On the picture | Round, over the picture at each edge | Full-width segments, edge to edge | On the picture |
| `outside` | Beside the picture | Outside the frame, so nothing covers the photograph | Short ticks in the shop’s pink | Under the picture |
| `veil` | Clean | Flat tabs flush to the edges, fading in on hover at 1024px and up; always there below that | One continuous rail whose current segment **fills** as the picture rests | Under the picture |
| `corner` | Cornered | Both together as one capsule in the bottom corner | Short thick ticks in the other corner | On the picture |

All four are the **same markup**. The token becomes one class and the stylesheet
does the rest, so there is no fourth template to keep in step and no look that
quietly has a control the others do not.

## What it does

- **Autoplay with a real stop.** It stops while the pointer is over it, while
  anything inside it has keyboard focus, under `prefers-reduced-motion`, while
  the tab is hidden, and when the **pause button** in the corner is pressed.
  The first three are floors rather than switches — the cards banner’s
  “Pause when the mouse is over it” is deliberately not read here, because a
  slideshow whose owner can turn the stop off is one that cannot be stopped.
- **Keyboard and screen reader.** The arrows and the bars are real `<button>`s
  with accessible names; the bar for the picture on show carries
  `aria-current="true"`; the left and right arrow keys move it once focus is
  inside; a polite live region announces the change a shopper made (and says
  nothing for an autoplay step, which would otherwise read the slider aloud for
  ever).
- **Arabic.** On `/ar` it runs the other way, the arrows swap ends and the bars
  fill from the other side. One number does it — `--kbbs-dirn`, written by the
  server from `Locale::isRtl()` — and there is no `[dir="rtl"]` rule in the
  section at all; everything else is a logical property.
  `docs/lane-bn2-shots/arabic-pair-1280.png` is the pair.
- **Swipe**, and it does not fight the page: `touch-action:pan-y` gives the
  vertical axis to the browser outright, so a downward drag scrolls immediately.
  Measured: a sideways touch drag moves the slider and scrolls the page 0px; a
  downward one scrolls 165px and does not move the slider.
- **No layout shift.** The frame’s height comes from `aspect-ratio` in the
  stylesheet, at two shapes — one from a tablet up and one below it — so it is
  known before any picture has been fetched. The first picture is preloaded and
  eager; every other one is lazy.

## What it costs

One query, and it is the query the cards banner already made:
`Banners::forHome()` is one JOIN that brings the set’s columns back alongside its
cards. Measured at 3 pictures and again at 24 — the same number both times.

## The script, and rule 4

This is the first storefront section in this application with a script in it.
`CLAUDE.md` rule 4 forbids **JavaScript that measures layout**, and this keeps
that rule exactly: the script holds **one integer** — which picture is up — and
writes it into `--kbbs-i`. Every pixel is arithmetic the browser does from the
stylesheet, with `100cqi` (the container-query unit) standing in for the thing a
resize listener would otherwise recompute. `SliderBannerTest` scans the script
for twenty measuring APIs by name and fails on any of them.

The scriptless version of this section exists — it is `::scroll-button()` plus
`:target`, which is what the cards banner uses — and it cannot do the two things
the owner asked for: arrows outside Chrome and Edge, and a bar that keeps telling
the truth after a swipe. Both were written up as the known limits of that section
and both are the request here.

**Without JavaScript it is still a slider.** The frame ships as a native
scroll-snap rail: every picture reachable by swipe and by keyboard, at the right
size, with no layout shift. The arrows and the bars are not drawn at all until
the script adds `is-js`, because a control that looks like a control and is not
one is a failure this repository has already paid for.

## What nobody has to do

Nothing. `banner_sets.kind` ships at `cards`, which is what every row in that
table already is, so applying the package changes no pixel on the shop until
somebody makes a slider.
