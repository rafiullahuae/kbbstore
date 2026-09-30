# Five backgrounds to choose from

*Lane BG. **The drawing is already off** — that part you decided. The gradient
is the part you are choosing: pick a letter and it ships.*

## What you asked for, and what is in the way

> *"you put some image on background of the whole site, which i don't want. i
> needed just light color, from one corner to another, not continue type, and
> such color will change continues slidghtly and very lightely … some gradient
> type colors, not just one."*

Four separate things, and three of them are in five lines of
`resources/css/kbb/kbb.css`:

```css
background-color:#FDEFF3;
background-image:var(--bg-botanical),
  linear-gradient(180deg,#FCE7EE 0%,#FDF2F5 26%,#FFF7F4 55%,#FBEAF0 100%);
background-size:1500px auto,auto;
background-repeat:repeat-y,no-repeat;      /* "not continue type" */
background-attachment:fixed,fixed;
```

| your words | the line | what it is |
|---|---|---|
| "some image on background" | `var(--bg-botanical)` | a drawing of leaves, petals and flowers, 9,130 bytes of SVG |
| "not continue type" | `repeat-y` | that drawing **tiles down the page**, over and over |
| "from one corner to another" | `180deg` | the colour runs **top to bottom**, not corner to corner |
| "change continues slightly" | — | built, and currently ships **off** |

**Being straight about what changed and when.** The drawing has been on the home
page and the cart for months — it is not new. What changed recently is that
`/shop/` and product pages used to be plain white by mistake, and were fixed, so
the drawing now shows up in more places than it used to. You are not wrong that
you do not want it; it is just not something that was added this month.

---

## First, a finding that matters more than the choice

**Your own cards cover the background almost completely.** Measured on the real
pages — the share of the first screenful covered by near-opaque white panels:

| page | at 1280 | at 390 |
|---|---|---|
| **home** | **100%** | 75% |
| /shop/ | 89% | **100%** |
| a product | 69% | **100%** |
| the cart | 90% | **100%** |
| the journal | 50% | 52% |

So whichever background you pick, this is how much of it you will actually see
when the page opens:

| | how much of the picture changes vs today |
|---|---|
| **/shop/** | **17.8%** |
| **a product page** | **16.1%** |
| the journal | 3.9% |
| **the home page** | **3.0%** |

**On the shop and product pages the new background reads clearly. On the home
page it barely shows at all.**

### And making the cards see-through does not fix it

The obvious answer is to let the background through the white cards. **Measured,
and it does not work:** dropping `.kbb-home .sec > .wrap` from 94% white to 62%
white changes **2,936 pixels of 1,152,000 — 0.3%**, which is *exactly* this
page's own frame-to-frame variation. In other words, **nothing measurable
changes.**

The reason is that the home page's first screenful is not covered by
translucent cards — it is covered by the **hero banner** (a full-width pink
panel) and the dark delivery ticker under it. Those are opaque by design.

**What it would actually take** to see a corner-to-corner wash on the home page
is to change the hero: let it sit *on* the background instead of covering it,
or narrow it. That is a design decision about your home page, not a background
setting, so it is not included here — say the word and it gets costed properly.

---

## The five

All five: **no drawing, no tiling, corner to corner, several colours, very
light.** They differ in four ways on purpose — direction, how far apart the
colours are, how far the colour travels as it shifts, and how fast.

| | name | direction | colours | travel | one full cycle |
|---|---|---|---|---|---|
| **A** | Ivory to rose | 135° — top-left to bottom-right | ivory → near-white → pale rose | small | 7 minutes |
| **B** | Corner light | 115° — flatter, more across than down | peach → pure white → deeper rose | medium | 5 minutes |
| **C** | Reverse fall | 300° — **runs the other way**, pink at the top-left | rose → near-white → warm ivory | medium | 6 minutes |
| **D** | Wide sweep | 160° — steeper, almost down the page | warm apricot → pure white → pink | **large** | **3 minutes** |
| **E** | Barely there | 135° | the faintest hint of warm and pink | tiny | **10 minutes** |

They really are different pictures, not five versions of one: measured against
each other on `/shop/`, **A vs C differs by 14.5%** of the page and **A vs D by
6.8%**.

### Every colour was checked before it was drawn

The shop's contrast floor is `#FCE7EE` — the darkest flat background any page
renders today. Nothing may go below it, or text gets harder to read. **All
twenty colours above clear it.** Three did not when they were first written:

| | was | luminance | became | now |
|---|---|---|---|---|
| B's last stop | `#FBE2EE` | 0.8107 | `#FCE7F1` | 0.8420 |
| D's third stop | `#FCE6F1` | 0.8364 | `#FCE7F1` | 0.8420 |
| D's last stop | `#F8DEEC` | **0.7826** | `#FAE8F2` | 0.8445 |

They were walked toward white until they passed — 16%, 2% and 29% of the way.
A palette that fails that test gets changed, not shipped.

### The pictures

`docs/bg-candidates/` — every candidate on the home page, `/shop/`, a product
page, the cart and the journal, at **390** and **1280**, plus `today-*.png` for
comparison.

The slow shift cannot be photographed, so `moment-<letter>-1/2/3.png` shows each
candidate at three points of its cycle on `/shop/`.

---

## What I recommend, and why

**B — Corner light.**

- It is the one whose diagonal reads most clearly as *corner to corner*: at 115°
  the colour travels mostly across the page, which is what your reference
  picture does. A and E at 135° read more as a soft vignette; D at 160° is close
  to the top-to-bottom you already have and did not like.
- It has the widest colour separation of the ones that stay comfortable —
  **peach → pure white → rose** — so it is visibly "gradient type colors, not
  just one" rather than one colour fading.
- Five minutes a cycle is slow enough that you will never catch it moving, which
  is what "slightly and very lightly" means.

**C is the one to look at if you want it obviously different**, because it runs
the diagonal the other way — pink at the top left, warm ivory at the bottom
right. It is the biggest change from today and the easiest to notice.

**E is there in case all of this is still too much.** It is very nearly white.

---

## Two things I need to decide with you

**1. The drawing — gone everywhere, or just off the background?**

`--bg-botanical` is used in three places, and the other two are not "the
background of the whole site":

- the page background (**this is the one you are objecting to — it goes**);
- `.kbb-home .about .im` — the picture panel in the "about" block on the home page;
- `.kbb-home footer::before` — a very faint texture behind the footer.

Its three siblings — `--bg-petals`, `--bg-corner`, `--bg-wave` — are used on
home-page section headers, the card thumbnails and the quiz panel. **None of
them is dead**, so none is being deleted quietly. Removing them from the page
background does not touch any of those. Tell me if you want them gone from those
places too and they go in the same change.

**2. Does the background stay still when you scroll?**

Today it does — `background-attachment:fixed`. It looks calmer, and with a
diagonal it means the whole page shares one continuous sweep instead of each
screenful repeating it. The cost is that `fixed` is slow on iPhone Safari on
long pages. All five candidates above keep it; if the shop feels sticky when
scrolling on a phone, this is the line to change, and it costs nothing to try
both.

---

## What has already changed, and what has not

**Done, because you decided it — the drawing and the tiling are gone.**
`var(--bg-botanical)` and `repeat-y` are out of the page background on every
page, including the journal and articles, which carry their own copy. Measured
on the real pages, before and after:

| | before | now |
|---|---|---|
| background layers | 2 | **1** |
| a drawing in it | yes | **no** |
| `background-repeat` | `repeat-y, no-repeat` | **`no-repeat`** |
| `repeat-y` anywhere in the built stylesheet | 1 | **0** |

`docs/bg-candidates/before/with-drawing-*.png` is what it looked like with the
drawing; everything else in that folder is shot against the shop as it stands
now.

**You can put the drawing back yourself.** Appearance → Page background →
**Botanical drawing**. It ships **off**, and while it is off it adds not one
byte to any page. It is still used on the home-page panels, the footer and the
card thumbnails either way — those are not "the background of the whole site".

**Not done, because it is what you are choosing — the gradient.** It is still
the top-to-bottom one, minus the drawing it used to carry. Picking a letter
below replaces exactly one line:

```css
:root{ --kbb-page-gradient: linear-gradient(180deg,…); }   /* kbb.css */
```

and the same line in `resources/views/partials/page-background-css.blade.php`,
which is how the journal and articles get it. A test holds those two identical,
so they cannot drift apart.

## When you pick

Picking a letter means one line in two files, and then:

1. the slow shift gets switched **on by default**, which is the second half of
   what you asked for and which you have now asked for twice;
2. `npx vite build`, and the built stylesheet ships with it.

That is the whole of it. It is deliberately one declaration so that answering
with a letter is the end of this, not the start of another round.

Reproduce any of this:

```bash
sh tools/bg-preview.sh 8996
BG_BASE=http://127.0.0.1:8996 node tools/bg-candidates.cjs
```
