# KBB Ad Studio

One self-contained offline HTML file (`dist/ad-studio.html`, the owner's file): pick a template, edit the
texts, offers and products, download post 1080×1350, square 1080×1080 and story 1080×1920. Zero network
requests: fonts, libraries, product cut-outs and the tabby / tamara badges are all inlined.

```bash
python3 build.py                      # -> ad-studio.html + dist/ad-studio.html
node tools/check-templates.cjs        # every template × 3 sizes: inside the canvas, no overlaps, 0 errors, 0 requests
node tools/check-editor.cjs           # the editor, storage and downloads
node tools/check-video.cjs            # Video mode (rewrites shots/video/*.png -- revert those)
node tools/shots.cjs post,square,story noeditor     # shots/templates-{post,square,story}.jpg
node tools/nd-shots.cjs [out.jpg] [set] [ids-regex] # National Day contact sheet
```

A template is data in `src/templates.js`: a layout family (`FAM.<name>`, called once per size) + palette +
texts + options + a product pool. Families must return the same element ids in the same order for every
size. New templates are appended last so no older template's product rotation moves.

## Templates (69)

**UAE & Gulf (24)** -- `ramadan-kareem` · `ramadan-nights` · `eid-al-fitr` · `eid-gifts` · `eid-al-adha` ·
`national-day` · `emirati-women` · `mothers-day` · `dsf` · `dss` · `hijri-new-year` · `white-friday` ·
`ramadan-iftar-box`, and the eleven National Day · Mega Offer designs below.

**UAE National Day · Mega Offer (11)** -- heading "National Day", "Mega Offer" in script, an editable offer
value ("Up to 50% off"), an Arabic line, 4-5 of the shop's newest products, button, tabby / tamara, "Selected
products · while stocks last". No year or number anywhere.

| id | name | look | kicker | button |
|---|---|---|---|---|
| `nd-silk` | Satin Ribbons | blush & pearl, four satin flag ribbons behind a pearl dish | CELEBRATE IN GLOW | Shop the Offer |
| `nd-pearl` | Pearl Arch | champagne, blush arch traced in flag lines, pearl podium | THE PEARL EDIT | Discover the Offer |
| `nd-night` | Rose Night | emerald-to-black night, satin petals, glass plinth | AN EVENING OF GLOW | Shop Now |
| `nd-gift` | The Gift Bow | canvas wrapped in a flag-striped ribbon, red bow | A GIFT FROM US TO YOU | Unwrap the Offer |
| `nd-edit` | Editorial | ivory magazine page, flag pinstripe edge, rose arch window | THE CELEBRATION ISSUE | Shop the Edit |
| `nd-bloom` | The Bloom | pearl ground, one great flower of pearl and flag-coloured petals | -- | Shop the Offer |
| `nd-gold` | Champagne Bow | ivory & champagne, double gold-foil frame, gold arcs, thin flag silk ribbons tied in a bow at the corner, gold-rimmed dais | A GIFT OF GLOW | Discover the Offer |
| `nd-garden` | Watercolour Garden | pastel sage, watercolour washes, red poppies and white anemones with black hearts and green leaves around a white dish | IN FULL BLOOM | Shop Now |
| `nd-lilac` | Lilac Glass | glossy lilac-to-pink gradient, floating bubbles, two-tier glass pedestal, wavy flag ribbon along the bottom | SPARKLE & GLOW | Grab the Deals |
| `nd-velvet` | Velvet Spotlight | deep wine velvet, gold serif, spotlight on a gold-rimmed velvet plinth, satin flag ribbon behind | INDULGE IN GLOW | Shop the Offer |
| `nd-sky` | Arch Sky | white & pearl, tall arch window with a red-white-green sky and a black hairline, bold sans + script | A FRESH NEW GLOW | Get the Deals |

Contact sheets: `shots/national-day.jpg` (the first six), `shots/national-day-2.jpg` (`nd-gold` … `nd-sky`).

**Global occasions (14)** -- `black-friday` · `cyber-monday` · `singles-1111` · `double-1212` · `new-year` ·
`valentines` · `womens-day` · `back-to-school` · `summer-spf` · `winter-care` · `diwali` · `christmas` ·
`black-friday-countdown` · `singles-glow`

**Sales & offers (13)** -- `flash-sale` · `percent-off` · `bogo` · `bundle-save` · `free-gifts` ·
`free-delivery` · `pay-later` · `limited-stock` · `restock` · `giveaway` · `loyalty` · `refer-friend` ·
`clearance`

**Products & routines (18)** -- `new-arrival` · `bestsellers` · `product-hero` · `routine-123` ·
`night-routine` · `ingredient` · `review` · `review-dark` · `kbeauty-edit` · `hydration` · `glass-skin` ·
`gift-sets` · `mens-skincare` · `sensitive` · `sunscreen-daily` · `vegan-clean` · `hair-body` · `pay-later-hero`
