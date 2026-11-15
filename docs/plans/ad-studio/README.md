# KBB Ad Studio

One self-contained offline HTML file (`dist/ad-studio.html`, the owner's file): pick a template, edit the
texts, offers and products, download post 1080×1350, square 1080×1080 and story 1080×1920. Zero network
requests: fonts, libraries, product cut-outs and the tabby / tamara badges are all inlined.

```bash
python3 build.py                      # -> ad-studio.html + dist/ad-studio.html
node tools/check-templates.cjs        # every template × 3 sizes: inside the canvas, no overlaps, 0 errors, 0 requests
node tools/check-editor.cjs           # the editor, storage, downloads, multi-select, groups, align, backgrounds, colours (rewrites shots/editor-390-edit.png)
node tools/check-video.cjs            # Video mode (rewrites shots/video/*.png -- revert those)
node tools/shots.cjs post,square,story noeditor     # shots/templates-{post,square,story}.jpg
node tools/nd-shots.cjs [out.jpg] [set] [ids-regex] # National Day contact sheet
node tools/editing-shots.cjs          # shots/editing/*-{1280,390}.png: multi-select, group in Layers, Background panel, colour control
```

## Editing (15 Nov 2026)

**Pick several** like Canva / Figma: Shift-, Ctrl- or Cmd-click adds or removes; drag a box on the empty ad to pick
everything it touches; Ctrl+A picks all (locked and hidden items stay out); on a phone, the **☐ Select** chip
makes plain taps add / remove. The pick gets one box: drag to move it all (smart pink guides snap to the ad's edges
and centre and to every other item's edges and centre; hold Alt to place freely), the four corners scale it all,
the round handle turns it all (Shift = 15° steps). The **arrange bar** over the ad aligns left / centre / right /
top / middle / bottom (to the selection, or to the ad), spaces 3+ evenly across or down, and matches width / height
to the first item picked. Multi panel (Edit tab): group, ungroup, duplicate, delete, lock, hide, forward, back,
opacity, text colour and fill for all.

**Groups**: Ctrl+G. A tap picks the whole group, which moves, scales and turns as one; double-click enters it to
edit one item (dashed outline), Esc leaves. Layers shows it folded open / shut with its items under it; Layers also
takes Shift (range) and Ctrl/Cmd (add / remove) clicks, the ⠿ grip drags a row (onto a group's item = into the
group), double-click a name to rename. Groups save with the design and the project file; downloads draw exactly as
ungrouped (the group is editing structure only).

**Background** (Style tab): None (see-through PNG; JPG / video get white) · Solid · Linear · Radial (up to 8 colour
stops: tap the bar to add, drag to move, Remove, Reverse; angle; radial centre) · Picture (Fill / Fit / Tile,
position, scale, blur, brightness, contrast, colour, an overlay colour with its own opacity, the colour behind) ·
a pattern on top (dots, grid, stripes, rays, lattice, waves, sparkles, petals) with colour and strength. A template's
own background opens in these controls with its real values.

**Colour control** (every colour swatch: text, icons, fills + 2nd gradient colour and angle, borders, shadows,
buttons, background): picker, hex (#RRGGBB or #RRGGBBAA), opacity, eyedropper (Chrome / Edge), recent colours, the
colours already in this design, the brand palette. **Colours in this design** (Style tab): tap one, choose its new
colour, and every use changes (each keeps its own opacity) -- or only in the picked items. Every one of these is a
single Ctrl+Z.

| Shortcut | Does |
|---|---|
| Click · Shift/Ctrl/Cmd+click | pick one · add / remove |
| Drag on the empty ad | marquee pick |
| Ctrl/Cmd+A | pick everything |
| Arrows · Shift+arrows | nudge 1 px · 10 px (of the full-HD file) |
| Alt while dragging | no snapping |
| Shift while turning | 15° steps |
| Ctrl/Cmd+G · Shift+Ctrl/Cmd+G | group · ungroup |
| Double-click a group · Esc | edit inside it · leave it (Esc again clears the pick) |
| Ctrl/Cmd+C · X · V · D | copy · cut · paste · duplicate |
| Ctrl/Cmd+] · [ | forward · back |
| Delete / Backspace | delete the pick |
| Ctrl/Cmd+Z · Ctrl/Cmd+Y (or Shift+Ctrl/Cmd+Z) | undo · redo (a whole drag, align or colour change is one step) |
| Ctrl/Cmd+S | save |

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
