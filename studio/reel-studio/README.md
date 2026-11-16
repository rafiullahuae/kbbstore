# KBB Studio

Your reels editor. It runs on your Mac and works offline. The only time it goes on the internet is the one-time
download of its speech models, and only after you press **Allow**.

## 1. Put it on your Mac

Unzip **KBB Studio.zip** and move the **KBB Studio** folder anywhere you like (Documents is fine). Everything the
studio makes stays inside this folder.

## 2. Open it the first time

1. Open the **KBB Studio** folder.
2. **Right-click** (or Control-click) **Start KBB Studio.command** → **Open** → **Open**.
   macOS asks this once because the studio does not come from the App Store. After that a normal double-click works.
   *On macOS 15 (Sequoia) and newer*, if it only offers "Done": open **System Settings → Privacy & Security**, scroll
   down to *"Start KBB Studio.command" was blocked*, press **Open Anyway**, then double-click it again.
3. A small Terminal window opens and the studio opens in your browser.
   **Use Google Chrome** (fastest captions and export, opens iPhone videos). Safari 17 or newer also works.
4. Keep the Terminal window open while you work. Close the studio's browser tab (or press **⏻ Stop**, top right)
   and it stops by itself a few seconds later.

## 3. The first-run setup (once)

The very first time, the studio shows a **Setup** screen with the speech models it needs for captions
(about 0.9 GB) and your free disk space. Press **Allow and download**. You can watch the progress, pause and
resume; a dropped connection carries on where it stopped, even after a restart, and every file is checked before it
is used. When it says **All set**, press **Open KBB Studio**.

The models are saved in the `models` folder inside KBB Studio and are never downloaded again. **Later** skips the
setup for today; **⚙ Models** on the home screen opens it again. A few more models are listed as *Optional* — they
are for features coming in the next update, so you can leave them unticked for now.

## 4. Making a reel

* **Home screen:** pick the company (top left), then drop a video on **Drop a video here** — it opens in the editor.
* **Do it for me** (the bar above the video): **💬 Auto captions** · **✨ Auto reel** · **✂️ Remove silences** ·
  **🎵 Add music** · **🎨 Suggest style** · **💥 Auto effects**. Every one of them is a single step you can undo
  with **⌘Z**.
* **Captions:** the **💬 Captions** tab on the right shows the transcript. Click a word to jump there, double-click it
  to correct it. **⚙ Settings** (top of the panel): *Fast* or *Accurate*, the language (Auto, English, Arabic, Hindi,
  Urdu), **Music & sound tags** (*Hide* — the default —, *Show ♪ icon*, *Keep as text*; captions also go quiet while
  only music plays) and **Words to spell right** (your brand, product, country and visa words; Whisper hears them
  first and near-misses are corrected). **🎨 Word style / Line style** under a selected word changes its text, size,
  colour, font, weight, highlight, emoji, animation and position (a dot marks it; **Reset to style** undoes it); drag a
  caption on the video to move that line (Shift-drag moves them all). **Script** shows the whole transcript as
  paragraphs — edit it there and the captions follow — with **Copy**, **TXT**, **SRT**, **VTT** (and bilingual) saved
  into the company's exports folder. **Style** has the template gallery: 60 templates, search, categories, ★ favourites.
* **Left panel:** **Music** and **SFX** (press ▶ to listen, drag a row onto the timeline or press **+**),
  **Templates** (recipes for the open company: **Use** sets the style for this reel, **Make reel** cuts one now),
  **Stickers** (Planet Careers also gets flags, maps and country stickers for 33 countries), **Text**.
* **⬇ Export** (top right) makes an MP4 (1080×1920 for reels; 30 or 60 fps; also 1:1, 4:5 or 16:9 if you tick them).

### Make it go viral (the 🔥 Viral tab)

One press each, and **⌘Z** takes any of them back in one step. Pressing one again replaces what it made before.

* **🪝 Hook builder** — writes 10 opening lines from what you say (English or Arabic, for beauty or visas), puts the
  best one on screen for the first 2 seconds with a zoom on the face and a sound hit, and fixes a black or blurred
  first frame. **⏩ Flash-forward** plays the best moment first ("wait for it…"), then the reel from the top.
* **🎣 Add a hook** — a video hook (snap zoom, freeze-frame + record scratch, whip-in, reveal, before → after flash,
  object toss, face close-up, speed ramp, shake, light leak) with a matching sound (stamp, boarding chime, ASMR taps,
  spray, shutter, riser …), landing on the music's first beat. Press again for the next pair. All 21 sounds are in
  the tab with ▶ to listen and **+** to add one at the playhead.
* **📈 Retention** — scores every second; red stretches show under the timeline ruler. **Fix all dead zones** adds
  jump cuts, punch-ins, text pops and stickers there.
* **⚡ Tighten** — cuts the breaths and gaps between words (never inside a word, with tiny fades so nothing clicks).
* **🔍 Smart zooms** on the words you stress · **Face in upper third** for 16:9 → 9:16.
* **📱 Safe zones** — shows where Instagram, TikTok, YouTube Shorts or Snapchat put their buttons and moves captions,
  text and stickers out of the way. The Export window warns you if anything is still under them.
* **🔁 Loop ending** — the last moment blends into the first frame so the reel replays seamlessly.
* **📣 CTA** — "Comment VISA", link in bio, save/share, DM, WhatsApp or call, in your colours, English or Arabic.
* **🔢 Chapters & steps** — a progress bar and "STEP 2/4" for list videos.
* **🅰️ A/B variations** — three versions with different openers, saved as three MP4s.
* **🛡️ Claim safety** — flags "guaranteed visa", "100 % approval", "cures acne", "no side effects", whitening … and
  offers safer wording (a guide, not legal advice).

### Translate captions (🌐 Translate, in the 💬 Captions tab)

One click turns the captions into another language, on your Mac, offline: **English ⇄ Arabic, Urdu, Hindi**, and
also French, German, Spanish, Italian, Portuguese, Russian, Turkish, Chinese, Tagalog, Bengali, Malayalam, Tamil and
Persian (any of them to any other). The captions' own language is picked up automatically (**From: Auto**).

1. **💬 Captions → 🌐 Translate**, pick the language (**To**) and how it goes on the video:
   * **Replace captions** — the video shows the translation instead of the original;
   * **Bilingual (both)** — original and translation together (a bilingual style is chosen for you);
   * **Add as a second track** — the translation on its own caption track at the top, the original stays below.
2. Press **🌐 Translate**. The first time for a language the studio asks once to download its translation model
   (**⬇ Get Arabic** etc. does the same ahead of time; 0.6–0.8 GB, saved in `models/`).
3. The review list shows every sentence **side by side: original | translation**. Lines worth a second look are
   marked in orange (a name or number had to be put back, the back-translation reads differently, it repeats itself,
   it came out much shorter or longer). Click a translation to **edit** it (Enter keeps it), **↻** translates that line
   again, **✓** approves it, **✓ Approve all** clears the marks. Edits go straight into the captions.
4. **⌘Z** undoes the whole translation in one step; **↺ Restore original** puts the original captions back at any time
   (the translations stay listed and can be shown again: **Replace / Bilingual / 2nd track**).
5. **Export** (bottom of the list): TXT, SRT or VTT of the original or of any translated language, into
   `workspaces/<company>/exports/` (file names end in `-ar`, `-ur`, `-en` …).

Accuracy helpers, always on: brand and product names (your company's **Glossary & do-not-translate** list, seeded with
the KBB brands and Planet Careers' programme names; "Schengen" gets its standard spelling in each language), numbers,
prices, percentages, dates, codes, links and emoji stay **exactly** as written; whole sentences are translated (not
caption lines), then cut back into the original lines so the timing stays natural and word-by-word highlights still
move word by word; Arabic and Urdu read right to left, Urdu in Nastaliq (Noto Nastaliq Urdu), Hindi in Devanagari.
**✨ Best quality (Claude)** will use your Claude key once *Connect Claude* arrives (with Brief → Designs); until then it is
switched off and nothing goes online.

## 5. Where your things are

| Folder inside KBB Studio | What is in it |
|---|---|
| `workspaces/<company>/projects/` | your saved projects, one folder each (the videos you add are copied in). Projects save themselves. |
| `workspaces/<company>/exports/` | your finished MP4s |
| `library/music/` | the starter music (27 tracks, made for the studio, free to use) |
| `library/music/user/` | **your own music** — see below |
| `library/sfx/` | sound effects (28, free to use) |
| `library/people/<occupation>/` | worker photos you saved from the 📷 Photos tab, each with a `.json` beside it (photographer, licence, source link) |
| `library/occupations/` | the 100 occupations (English / Arabic, uniforms, tools, search words) the Photos tab and the Ad Studio use |
| `settings/` | your Pexels / Unsplash / Claude keys and preferences (only the engine reads this; never put it in a shared folder) |
| `workspaces/<company>/designs/` | saved designs, AI pictures (`images/`, each with its prompt and licence) and saved colour schemes |
| `brands/` | the brand kits: colours, fonts, logos, end cards (KBB, Planet Careers, and any company you add) |
| `models/` | the speech models, downloaded once by the setup |

`<company>` is `kbb`, `planet-careers`, or the short name of a company you added.

### Worker photos (the 📷 Photos tab)

For Work Abroad reels and ads: real photos of plumbers, nurses, drivers, chefs … from **Pexels** and **Unsplash** (free
for commercial use). Once: get your free keys (pexels.com/api and unsplash.com/developers → the *Access Key*), open
**📷 Photos → ⚙ Photo sources**, paste each, **Save**, **Test**. Then pick an occupation (or type), choose
**Pexels / Unsplash**, shape and region, **Search**. The studio asks before it goes online (*Allow once* or *for this
session*). **⬇ Save** puts the photo in `library/people/<occupation>/` with the photographer and licence; under
**Saved photos**, **+ Add to reel** puts it at the playhead and **⬇ For Ad Studio** saves a copy to use with
*Put a photo here…*. Keep the credit shown under each photo when you can.

## 6. Adding your own music

Copy your `.mp3`, `.m4a` or `.wav` files into **`library/music/user/`** (inside the KBB Studio folder). Open the
**Music** tab and they are listed with the starter tracks; the studio measures their tempo once so **Add music** and
**Auto reel** can cut on the beat. Use only music you have the rights to.

## 7. Adding a company

Top left, click the company name → **+ New company**. Give it a name and industry (for example *Restaurant / cafe*),
pick its colours and fonts, upload a logo if you have one, and write its contact line and end-card call to action.
Press **Create company**. It gets its own projects, exports and brand kit (saved in `brands/<company>/`), and the
recipes that fit its industry. **✎ Edit company** changes any of it later. Switching company is instant.

## 8. Create — the one-click content tools

The **🚀 Create** tab (right column, beside Captions) and the **Create** card on the home screen. Every button is one
step you can undo with **⌘Z**.

* **Viral captions** — 1–3 words at a time in the middle of the screen, the spoken word in your company colour,
  moved off faces and products, and product names, prices and countries popping up as their own items.
* **Auto b-roll** — fills the long talking stretches with a matching shot: your own clips and photos (put them in
  `workspaces/<company>/library/`; the studio looks at each picture once to know what is in it), KBB product shots,
  or the country's landmark for Planet Careers.
* **Proven formats** — before/after, "3 products for…", GRWM, texture close-up, dupe or worth it; visa approved,
  documents in 30 s, myth vs fact, country A vs B, cost breakdown, student testimonial, day in the life. Each builds
  the whole timeline; drop a clip on a slot (or pick it from the list) to fill it.
* **Series kit** — "Visa Tip #12": opener, episode badge and the same look on every episode; the number counts up.
* **Cover** — picks the best frame, adds a bold title, checks it still reads small on your grid, saves a 9:16 cover
  and a 3:4 grid cover (PNG) in `exports/`.
* **Post copy** — caption, search keywords, 5 hashtags and comment prompts in English and Arabic, written on your
  Mac (install the optional *Writing* model in ⚙ Models for the best text; without it a built-in writer is used).
* **Every shape** — the same edit as 9:16, 4:5, 1:1 and 16:9, re-laid out, exported in one go.
* **Long video → shorts** — a 10–30 min webinar becomes 8–12 shorts of 20–60 s, each its own reel with a hook,
  captions and your call to action; **Export all** saves them.
* **Teleprompter & record** — your script scrolls over the camera; record as many takes as you like, the best one
  (energy, fewest "umm"s, eyes on the lens) is marked for you.
* **Trending-sound cue sheet** — tap or type the tempo of the sound you will add in Instagram/TikTok (or let the
  studio measure it), and the cuts move onto its beats; a cue sheet and a click guide track are saved in `exports/`.

## 9. Brief → Designs (ad pictures from your own words)

On the home screen, **🖼 Brief → Designs**: type what you want, in English or Arabic, the way you would tell a designer —
*"National Day: 30% off Anua and BoJ, story and post, luxury"*, *"Work abroad: welders wanted for Poland, apply on
WhatsApp"* — and press **✨ Make designs**.

* **What I understood** shows how the studio read it: the occasion, the offer, the brands and products (from the KBB
  catalogue — a brand without a product photo yet is named in the words and flagged), the country, intake and jobs for
  Planet Careers, audience, tone, languages, sizes and the button. It reads briefs offline; the optional *Writing* model
  (⚙ Configure → Models) and **Smart brief (Claude)** help with long, free-form briefs, and the offline reader checks
  whatever they say (no invented discounts, products or promises).
* You get **6–8 different designs** (spotlight, editorial frame, split, big number, offer badge, floating card, arch,
  tiles, poster, diagonal band) in your company's colours, fonts and logo, in **story 1080×1920, post 1080×1350 and
  square 1080×1080**, with English and Arabic lines. Every one passes the design rules in every size: text contrast
  4.5:1 or more (measured on the real pixels), nothing in the Instagram/TikTok button areas, no text smaller than 24 px,
  no overlaps, products never cropped, one clear headline and one button.
* **Click a design** to edit it: click anything to select it, drag to move, drag the yellow corner to resize, change the
  words, size, font, colours (scheme colours or any colour), swap a product or a picture, change the colour scheme or the
  artwork. The rules are re-checked as you go. **⌘Z** undoes. **💾 Save** keeps it in `workspaces/<company>/designs/`.
* **⬇ Export**: PNG or JPG, **Full HD** or **4K**, this size or every size → `workspaces/<company>/exports/designs/`.
* **🎬 Make a matching reel** starts a reel with the same brief, the matching recipe and this design saved as a picture.
* **Visuals** (right): **🖼 AI pictures** — backgrounds, scenes and worker photos made **on your Mac** by an open picture
  model (install *Pictures (AI)*, about 1 GB, once): pick an idea or type your own, 2–6 pictures per click, **Lock seed**
  to get the same picture again, click one to use it (it is enlarged ×2 and saved with its prompt in
  `workspaces/<company>/designs/images/`). The words of the ad are never drawn into a picture, and product photos are
  always the real ones. **🎨 Colours** — schemes made for this brief (your kit, the occasion, the mood, the country's
  flag), all contrast-checked; ★ saves one to the company. **✳ Icons** — 1,539 open icons, picked by meaning, in line /
  duotone / badge styles. **✦ Artwork** — gradients, mesh, ribbons, confetti, light, patterns, frames, always in your
  colours.
* In the reel editor, the **🖼 Designs** tab lists your saved designs; **+ Add to reel** puts one at the playhead.

## 10. ⚙ Configure (one page for everything you enter)

**⚙ Configure** (top bar, on the home screen and in the editor): **Companies** (edit kit: logo, colours, fonts) ·
**Contact & social** (website, phone, WhatsApp, email, Instagram, TikTok …, the button wording in English and Arabic) ·
**Connect Claude** · **Photo sources** (your Pexels and Unsplash keys) · **Models** (installed / available with sizes,
download, remove) · **Folders & storage** (sizes, *Open in Finder*) · **Languages** (caption language, translation
targets, ad copy languages) · **About**.

**Connect Claude (optional).** Without it everything works offline. With it: *Smart brief* and *Best quality*
translation. Paste your key (console.anthropic.com → API keys), **Save**, **Test**. The key is kept by the studio in
`settings/` (only your Mac user can read it), never in a project, a design, an export or a log; the page shows only its
last four characters. The studio asks once per session before anything goes to Claude, and then sends **only the brief
text and the catalogue names** (or the caption lines to translate). **Disconnect** deletes the key. Default model: Claude
Sonnet 5.5 (Opus 5.5 and Haiku 5.5 can be picked).

## Keyboard

Space play/pause · J K L back/stop/forward · S split · Delete remove · Shift+Delete ripple delete ·
⌘Z / ⇧⌘Z undo/redo · ⌘C ⌘V ⌘D copy/paste/duplicate · ⌘G / ⇧⌘G group/ungroup · M marker ·
← → one frame · + − timeline zoom · ⌘E export · ? all shortcuts.

## If something goes wrong

* **"KBB Studio needs its engine"** in the browser: the Terminal window was closed. Double-click
  **Start KBB Studio.command** again.
* **The download stopped:** press **↻ Retry** in the setup; it carries on where it stopped.
* **Not enough space:** the setup says how much to free before it starts.

---

## For developers

* `engine/` — Go 1.24, no dependencies (`engine/build.sh` builds `kbbstudio-mac-arm64`, `kbbstudio-mac-amd64` and a
  Linux one for tests; `go test ./engine`). 127.0.0.1 only, random port, COOP/COEP; the file API is locked to the
  folder; `/models/` is served read-only without the session token (workers); `/net/*` and the model installer only
  after the app's Allow, only to huggingface.co, its redirects only to `*.hf.co` / `*.huggingface.co`.
* `app/` — vanilla ES modules, no build step. `app/core/` is the contract; `app/editor/` the editor;
  `app/captions/` (R2) and `app/auto/` (R3) register through `app/core/modules.js` and `core/render.js`.
* `models/manifest.json` — the ONE list of model files (built by `tools/make-model-manifest.py` from Hugging Face
  tree listings). The engine installs from it; `app/captions/models.js` loads from the same `models/<repo>/` paths.
* `vendor/` — pinned third-party code with licences: transformers.js 3.8.1 + onnxruntime-web, MediaPipe tasks-vision
  0.10.21 + BlazeFace, mp4-muxer 5.2.2, mp4box.js 0.5.2, caption fonts (OFL). `library/LICENSES.md` lists the rest.
* `app/viral/` — the Viral engine (hooks, retention, tightening, safe zones, loop, CTA, chapters, A/B, claims); sound hooks
  in `library/sfx/hooks/` from `tools/v-sfx.cjs`; checked end to end by `tools/v-media.cjs` + `tools/v-check.cjs`.
* `tools/` — `int-media.cjs` + `int-check.cjs` (end-to-end), `check-r1.cjs`, `check-models.cjs [--real]`,
  `r2-check.cjs`, `r3-check.cjs`, `c-media.cjs` + `c-fetch-models.cjs` + `c-check.cjs` (content / Create tab),
  `package-mac.sh` (→ `dist/KBB Studio.zip`, models excluded).
  `r2-check.cjs`, `k-check.cjs` + `k-editor.cjs` (captions round), `r3-check.cjs`, `package-mac.sh` (→ `dist/KBB Studio.zip`, models excluded).
* `app/designs/` — Brief → Designs: `lib/brief.js` (rule reader + `validateSpec`), `llm.js` (local Qwen / Claude),
  `lib/copy.js`, `lib/palette.js`, `layouts.js` (10 families, the Ad Studio's family idea ported to canvas px),
  `render.js` (one canvas renderer for thumbnails, editor, rules and export), `rules.js` (the scorer), `generate.js`,
  `art.js`, `icons.js` (vendored Lucide), `imagegen/` (SDXS worker, prompts, picture check), `screen.js`, `editor.js`.
  Checked by `tools/d-check.cjs` (+ `d-claude-mock.cjs`, `d-briefs.mjs`; `d-gen-probe.cjs` / `d-imagegen-probe.cjs` for
  contact sheets and the model alone).
* `app/settings/` — ⚙ Configure (`configure.js`) and the Claude client (`claude.js`: `claudeStatus()`, `claudeRun({task})`
  for other modules; tasks `test`, `brief`, `translate`). The engine side is `engine/settings.go`: the key in
  `settings/claude.json` (0600), calls only to `https://api.anthropic.com/v1/messages` after the per-session grant,
  `anthropic-version: 2026-10-21`, structured JSON output, refusal fallback on Sonnet/Opus 5.5.
* The app name lives in `app/core/config.js` `APP_NAME` (and `appName` in `engine/main.go`, the launcher's file name).
