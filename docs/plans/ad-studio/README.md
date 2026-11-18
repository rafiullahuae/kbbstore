# KBB Ad Studio

One self-contained offline HTML file (`dist/ad-studio.html`, the owner's file): pick a template, edit the
texts, offers and products, download post 1080×1350, square 1080×1080 and story 1080×1920. Zero network
requests: fonts, libraries, product cut-outs and the tabby / tamara badges are all inlined.

**Inside KBB Studio (18 Nov 2026).** `build.py` also copies the build into `studio/reel-studio/app/adstudio/`, where
KBB Studio's engine serves it (home tile and top bar → 🎨 Ad Studio). There `src/kbbdisk.js` (it sees the engine's
`rs-token`) moves storage to `~/KBB Studio Data/adstudio/` through the engine (designs, pictures, companies, drafts,
video projects and clips; IndexedDB stays as a cache) and shows **◀ KBB Studio**. Opened as a file nothing changes,
except a banner when it holds designs: *Backup everything* → one `.json` (designs, pictures, companies with favourites
and brand kits, drafts, recent colours, video projects with clips up to 300 MB), which **Import backup** merges by id
(newer wins, nothing twice). `studio/reel-studio/tools/adstudio-check.cjs` checks all of it end to end.

```bash
python3 build.py                      # -> ad-studio.html + dist/ad-studio.html
node tools/check-templates.cjs        # every template × 3 sizes: inside the canvas, no overlaps, 0 errors, 0 requests
node tools/check-editor.cjs           # the editor, storage, downloads, multi-select, groups, align, backgrounds, colours (rewrites shots/editor-390-edit.png)
node tools/check-video.cjs            # Video mode (rewrites shots/video/*.png -- revert those)
node tools/shots.cjs post,square,story noeditor     # shots/templates-{post,square,story}.jpg
node tools/nd-shots.cjs [out.jpg] [set] [ids-regex] # National Day contact sheet
node tools/editing-shots.cjs          # shots/editing/*-{1280,390}.png: multi-select, group in Layers, Background panel, colour control
node tools/check-companies.cjs        # companies, Planet Careers, country picker, KBB 0-diff (tools/kbb-baseline.json); writes shots/companies.png
node tools/pc-shots.cjs [ids-regex]   # shots/planet-careers-{1..6}.jpg: every Planet Careers template in post / square / story
node tools/check-occupations.cjs      # Work Abroad: 44 templates, occupation picker sweep, honest copy, photo slot + cut-out; shots/work-abroad-picker.png
node tools/wa-shots.cjs [ids-regex]   # shots/work-abroad-{1..5}.jpg: every Work Abroad template in post / square / story
node tools/check-layout.cjs           # the app shell at 1280×800 … 2560×1440 + 1024, Ads and Video, inside KBB Studio and standalone; PNG pixels vs tools/layout-export-baseline.json
```

## Layout (18 Nov 2026) -- fit to screen, like the video editor

The owner: *"can u plz also make the ads studio overall layout fit to screen, same like video editor"*. From 900 px wide
the studio is an app shell like `studio/reel-studio/app/editor` (editor.css's spacing, radii, type sizes and, inside
KBB Studio, its dark surfaces): it fills the window exactly and the page never scrolls.

* **Top bar**: ◀ KBB Studio · Ads | Video · company + Edit company · design name · Country / Occupation (when the
  design has them) · undo, redo, Save and the status line. In Video mode: the video's name, New, undo, redo, Save.
* **Left panel**: Templates · Elements (Add + Layers) · Products. **Right panel**: Properties (the picked item) · Style ·
  Motion · Export. Picking something on the ad shows its properties on the right and Add + Layers on the left. Each
  panel scrolls on its own; drag its inner edge to resize (double-click = default width), the ◧ / ◨ buttons in the
  bar under the design shut and open them. Widths and shut panels are kept in this browser (`kbbas:layout`). Under
  1180 px the left panel starts shut.
* **The design** is fitted to its room (Fit, the default, in CSS); zoom 50–400 % of the full-HD file from the bar
  under it, Ctrl/Cmd + / − / 0 or Ctrl/Cmd + wheel (pinch); bigger than the room it pans (scroll, or hold Space and
  drag). **Video mode**: the picture fitted the same way, the timeline along the bottom, the options on the right.
* Downloads are untouched: the full-HD files are drawn off-screen at 1080 px wide as before (`check-layout` compares
  their pixels with `tools/layout-export-baseline.json`, made from the build before the change). Before / after:
  `shots/layout/`.

## Work Abroad (16 Nov 2026) -- image-first hiring ads

The owner: *"not just for study abroad, i need work abroad also, for different occupations, and i don't want generic
text ad. i want to focus more on the images, specially the pictures of the workers, employees in specific uniform."*

**44 templates** (Planet Careers set, category *Work abroad*; any company on that set gets them in its own kit), 11
layout families in `src/templates-wa.js`: We're hiring (worker hero + landmark + tools + badges) · Job card · Split ·
Poster · Collage (duo / trio of occupations) · Uniform & tools (call-outs) · Team · Staff pass · Journey (home → abroad) ·
How to apply · Urgent hiring (open roles list). Each in 4:5, 1:1, 9:16.

* **The worker is the hero.** Every design has a worker photo slot that ships with a placeholder figure in that
  occupation's uniform (faceless flat illustration, drawn for KBB Studio -- real photos cannot be bundled). Select it →
  **Put a photo here…**: his photo fills the slot (cover, the head kept) and stays a slot. **✂ Cut out the person**
  removes the background offline with MediaPipe's selfie segmenter (Apache-2.0, the model KBB Studio's Look tab uses),
  shipped as **`ad-studio-cutout.js` -- keep it in the same folder as `ad-studio.html`**; it loads only on the first
  cut-out, so the studio stays ~2.3 MB. One Ctrl+Z brings the background back.
* **Occupation picker** (top bar, beside Country): 100 occupations in 10 sectors from
  `studio/reel-studio/library/occupations/occupations.json` (the same list as KBB Studio). One click swaps the worker
  figure (a photo he put in stays), the job title in English and Arabic ("Welders", "Work in Poland as a welder",
  "مطلوب لحّام"), the three tool icons and names, and the accent colours; his other edits stay; Ctrl+Z undoes it. The
  Country picker works alongside it. Edit → Add also places any occupation's worker (as a photo slot) or any tool icon.
* **Honest by construction:** salary, visa type, contract, start date, openings are `‹edit›` boxes; every design carries
  "Requirements and terms confirmed at consultation."; no number, promise or "guaranteed" anywhere (check-occupations
  fails on a digit, currency, % or "guarantee" outside an ‹edit› box).

Contact sheets: `shots/work-abroad-1.jpg` … `-5.jpg`, `shots/work-abroad-picker.png`.

## Companies (16 Nov 2026)

The owner: *"create an environment 'Planet Careers' and keep the related stuff there. and also allow me options to create
any new company etc, and i will be able to switch between one to another."* The **company switcher** sits in the top
bar: KBB · Planet Careers · **＋ New company…** · **Import company…**, and **Edit company** next to it. A company is a
brand kit in the shape of `studio/reel-studio/brands/kit.schema.json` (name, full name, industry, colours main / second /
accent / light / ink, heading + body font, contact line + website / phone / WhatsApp / email / Instagram, default button
EN + AR, payment badges -- tabby / tamara only for KBB) plus what this studio keeps for it: its **template set**
(KBB beauty & retail, or Planet Careers study & visas), its **favourite templates** (☆ Add to favourites, ★ Favourites
chip) and its Style → Brand kit switches and logo picture. Every saved design carries its company (`doc.co`); each
company lists only its own designs and has its own unsaved draft, so switching is instant and loses nothing. Stored
offline with the existing storage (IndexedDB `kv/companies`, `kv/company`; KBB's brand kit stays in `kv/brand`).
**Edit company → Export company (.json)** writes `KBB-Studio-company-<id>.json` (`{app:'kbb-studio', kind:'company',
kit, studio, projects, images}`) -- the kit fields are kit.schema.json's, so it moves into KBB Studio as is; Import
company reads it back (validated field by field). The two seeds come from the kit.json files in `assets/companies/`.

**KBB is untouched**: its 69 templates draw byte-for-byte as before (`tools/kbb-baseline.json`, 207 renders, checked
by check-companies). A new company on the Planet Careers set gets those designs in its own colours, fonts, name and
contact line; on the KBB set, the KBB designs with its brand colours.

## Planet Careers (51 templates × 3 sizes)

Drawn from the company kit -- the real brand from theplanetcareers.com (owner, 16 Nov 2026): navy `#002768`, red `#BE0B32`,
white, slate `#556171`, light `#EFF4FA`, Afacad (vendored from npm, OFL) + Cairo, and the site's own logo (white-on-dark on navy grounds;
made by `studio/reel-studio/brands/planet-careers/source/make-logos.py`, never redrawn). `kitPal` turns any kit into roles
(text 4.5:1 on its ground; an accent that cannot be text on the primary becomes pills, fills and lifted icons). 17 layout families in `src/templates-pc.js` (spliced into templates.js by build.py): hero, boarding pass,
postcard arch, map split, intake calendar, steps timeline, checklist, visa-approved story (photo slot + stamp),
testimonial, event, countdown, top-5 carousel cover, myth/fact + FAQ, consultant / office, offer, language test, greeting.

* **Study abroad (21)** -- study visa for the UK, Canada, Australia, Germany, Ireland (hero) · USA, New Zealand, France,
  Malta (boarding pass) · Italy, Spain, Portugal, Netherlands (postcard) · Poland, Hungary, Sweden (map) · intake open
  September / January / May · scholarships · scholarships by country
* **Visas & PR (7)** -- work permit (country hero, process steps) · PR pathway (Canada) · PR (Australia) · visit visa ·
  tourist visa · family & spouse visa
* **Language & tests (3)** -- IELTS · PTE · German A1–B2
* **Services (7)** -- free consultation · how it works · documents checklist (study, visit) · why choose us · meet your
  consultant · visit our office
* **Stories & trust (3)** -- visa approved (two) · client review   **Events & deadlines (3)** -- webinar · expo · deadline
* **Tips & carousels (3)** -- top 5 destinations cover · myth vs fact · FAQ   **Greetings (4)** -- Eid al-Fitr ·
  Eid al-Adha · UAE National Day · New Year

Every template has an Arabic line, the company's contact line and, where facts are involved, a "rules change -- we
confirm the current official requirements" note; numbers, dates, names and scores are `‹edit›` placeholders. No visa
rule is written as fact. Photo slots (client, consultant, speaker): pick the slot, **Put a photo here…**, and the picture
takes its place and shape.

**Country picker** (next to ▶ Play, on any country template): one click swaps the flag, country outline, landmark
silhouette, the flag-colour stripes and the country's name in English and Arabic -- the owner's other edits stay; one
Ctrl+Z undoes it. 33 countries (R3's pack, copied into `assets/companies/countries/`: flag-icons MIT, outlines and
landmarks by lane R3): UK, Canada, Australia, New Zealand, USA, Ireland, Germany, France, Netherlands, Italy, Spain,
Portugal, Poland, Malta, Hungary, Czechia, Lithuania, Latvia, Sweden, Finland, Denmark, Austria, Belgium, Cyprus, Greece,
Switzerland, UAE, Japan, South Korea, Singapore, Malaysia, Türkiye, Estonia. Edit → Add also places any flag, outline or
landmark (recolourable). The art travels as one gzip + base64 pack (≈140 KB in the file) unpacked once at start.

**Template browser**: set (this company's / KBB / Planet Careers / all) · category chips · ★ Favourites · country (shows
the country templates drawn for that country; a pick opens in it) · preview size · search.

Contact sheets: `shots/planet-careers-1.jpg` … `-6.jpg`, `shots/companies.png`.

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

## KBB templates (69)

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
