# Image SEO — the owner's guide

**Where:** Admin → **Catalog → Image SEO** (sidebar, after Product editor).
**Who:** the owner and Store Manager accounts (permission "Rename product
pictures for SEO and bulk-write their alt text" in Platform → Users & Roles).
Content Editors and Support cannot open it.

It does three things for the products you pick:

1. **Scores** every product picture out of 10 — the badge in front of each
   picture's address — and says what cost the points.
2. **Renames the files** to the product name, a different word order for each
   picture, and keeps every old address working (it redirects to the new one).
3. **Writes alt text** in bulk, which you can edit line by line first.

Nothing changes on the shop until you press **Start** or **Apply**, and every
run can be undone from the **History** tab.

---

## Tab 1 · Find

Search by product name, SKU or brand, or pick a brand or category. Then
**Show**: everything, *not renamed yet*, *score below 8/10*, or *missing alt
text*; and **Order**: A–Z, **needs attention first** (lowest scores at the top)
or newest. Press **Search** — the list does not reload on every key you type.

Each product shows its **lowest** and **average** score, then every picture:

```
 [4/10]  https://extrabeauty.ae/uploads/products/20261005-101010-a1b2c3.jpg
         −0.5 camera or random file name · −1.5 no brand in file name · …
         will rename → medicube-pdrn-eye-patches.jpg   → 10/10 after
         Alt: Medicube PDRN Eye Patches (automatic) · main
```

Tick a product (all its pictures) or single pictures. **Select this page** and
**Select all N matches** select in bulk. The black bar at the top counts what
you have selected and takes you to the next tab.

A picture is **skipped**, with the reason shown, when:

- it is still on the old website (not on this shop yet — bring it across first
  in Store Import → pictures);
- the file is missing from the server;
- the product title has no English words (an Arabic-only name);
- **two products share the same picture** — tick *Include shared pictures* on
  the Rename tab to rename it anyway (both products keep showing it; it is
  named after the product you are renaming).

## Tab 2 · Rename files

1. Choose the naming style:
   - **Word-order variations** (recommended) — the scheme you described;
   - **Name + view number** — `…-2`, `…-3`.
2. Press **Preview changes**: every `old → new` path, and what will be skipped.
3. Press **Start renaming**. A progress bar shows each product as it is done,
   with the number of places updated and files moved. **Stop after this step**
   pauses it; **Resume** (here or on History) carries on exactly where it
   stopped. Pressing Start twice cannot rename anything twice.
4. When it finishes, the screen checks the live site: *"✓ Checked live: …
   redirects, and … loads."*

### The names

For **Medicube PDRN Eye Patches** with five pictures:

| Picture | File name |
|---|---|
| 1 (main) | `medicube-pdrn-eye-patches.jpg` |
| 2 | `pdrn-medicube-eye-patches.jpg` |
| 3 | `eye-patches-pdrn-by-medicube.jpg` |
| 4 | `pdrn-eye-patches-medicube.jpg` |
| 5 | `medicube-eye-patches-pdrn.jpg` |

The title is read as **brand** (Medicube), **key words** (PDRN) and **product
type** (eye patches), and each picture gets the next order of the three. When
the orders run out the picture's position is added (`anua-toner-4`). The rules,
which are Google's own advice for file names:

- lower case, plain English letters and numbers, words joined by **hyphens**;
- accents become plain letters (Crème → creme), apostrophes go (d'Alba → dalba);
- the brand appears once, whether or not the title already starts with it;
- joining words (and, with, for, of…) and repeated words are dropped;
- at most 8 words and 70 characters — extra key words are dropped from the end,
  never the brand or the product type;
- the file keeps its folder and its type (`.jpg` stays `.jpg`, `.webp` stays
  `.webp`); a WebP's kept original `.jpg` is renamed with it;
- never the same name twice; `-2` is added only if another file already has it.

### Why nothing breaks

For every picture, in this order:

1. The new name is created **beside** the old one (same bytes), together with
   every phone-sized copy (200/400/800 px), banner sizes, crops and the share
   image — so the shop's `srcset` stays complete and phones keep getting small
   pictures.
2. Every place that names the file is updated in one database transaction —
   the product's main picture and gallery, its variants, its alt-text keys, the
   description and other text, Journal posts, pages, banners, Spotted, settings
   and email templates — and the Media Library row.
3. Before that is saved, the system checks that **nothing still names the old
   file** and that **the old address already redirects to the new one**. If
   either check fails, that picture is put back exactly as it was and reported
   as *rolled back*; the product's other pictures carry on.
4. Only then is the old name removed. From then on the old address answers
   **301 → new address**, in one step, for the picture and for each of its
   phone-sized copies — so pages cached anywhere, links shared on WhatsApp and
   pictures already in Google Images keep working.

## Tab 3 · ALT text

Pick a template:

- **Natural variations** (recommended): "Medicube PDRN Eye Patches",
  "PDRN Eye Patches by Medicube", "Medicube PDRN Eye Patches – Eye Care",
  "PDRN Eye Patches by Medicube – view 4";
- **Name + view**: "Medicube PDRN Eye Patches – view 2 of 5";
- **My own wording**, with `{name}` `{brand}` `{full}` `{category}` `{index}`
  `{total}` (one line for the first picture, one for the rest).

**Keep alt text somebody already wrote** is on by default. Press **Preview alt
text**, change any line (the best alt says what that photo shows: "patch on the
under-eye", "the box, back with ingredients"), then **Apply alt text**.

Rules: 5–125 characters; no "image of"; not the same on two pictures of one
product.

**English only.** The shop stores one alt per picture, not one per language,
and a written alt is also shown on the Arabic shop. A picture with no written
alt keeps the shop's automatic Arabic alt on /ar.

## Tab 4 · History

Every run: when, who, how many done / skipped / rolled back, its log, and
**Undo**. Undo of a rename gives every picture its old name back (and the new
name then redirects to the old). Undo of an alt run puts back the alt text that
was there before — unless someone has changed it since.

---

## The score, out of 10

100 points, shown as points ÷ 10 (35 → 4/10). **✓ at 8/10 or better.**

| Points | | What earns them |
|---|---|---|
| 15 | File name | lower case, words joined by hyphens |
| 15 | File name | contains the brand |
| 15 | File name | contains the product's own words (7 for one word) |
| 10 | File name | 3–8 words, at most 75 characters |
| 5 | File name | not a camera, random or resized-copy name (IMG_1234, 20261005-101010-ab12cd) — a random name also loses the two lines above |
| 10 | Alt text | written for this picture (5 for the shop's automatic one) |
| 10 | Alt text | 5–125 characters |
| 15 | Alt text | names the product (7 for only the brand or one word) |
| 5 | Alt text | no "image of", no word stuffed three times, differs from the other pictures |

Colours: **red 0–4**, **amber 5–7**, **green 8–10**.

**In the Media Library** each product picture shows its score, and a **green
✓** when it was renamed by Image SEO **and** scores 8/10 or more. The detail
panel says the same in words. The first time you open Image SEO it scores the
whole library by itself (a line shows how many are left).

---

## What Google actually rewards (so you spend the effort in the right place)

1. **Alt text** is the strongest signal you control: it is what Google Images
   reads and what a screen reader says. Describe the photo; include the product
   name naturally; do not list keywords.
2. **The page around the picture** — the product title, description and the
   words near the photo — tells Google what it shows.
3. **An image sitemap and Product structured data** help Google find every
   photo (built by the SEO lane).
4. **The file name helps a little**: a descriptive, hyphenated name beats
   `IMG_1234.jpg`, which is why this exists — but it is the smallest of the four.
5. **Rename once, then keep names stable.** Every rename is a move Google has
   to re-learn; the 301s preserve what is already indexed, but do not rename
   the same pictures again and again.
6. **Do it before the switch to kbeautybliss.com**, so Google's first crawl of
   the new domain already sees the final names and nothing has to be moved
   twice.

## After the first run on the live shop (one check, 30 seconds)

The screen's live check tells you if old addresses redirect. On Cloudways a
missing picture normally reaches the shop, which answers the 301. If the live
check ever says the old address answered **404**, the web server is answering
missing pictures by itself. In SSH, `curl -I https://extrabeauty.ae/<old path>`
should show `HTTP/1.1 301` and a `location:` with the new name; if it shows
404, ask Cloudways support to "pass requests for missing static files to
index.php (Laravel)" — nothing on the shop page is broken in the meantime,
because the pages already point at the new names.

## Good to know

- A product whose editor tab was open during a rename and is then saved could
  write the old addresses back; they still redirect, and the next Image SEO run
  shows them as *"will fix address"* and re-points them. Close product editor
  tabs before a big run.
- Pictures on the old WordPress site are not touched — bring them across first.
- Run brand by brand if you like: Find → Brand → Select all matches → Rename.
