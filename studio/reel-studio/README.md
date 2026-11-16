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
  to correct it, and choose a caption style in the **Style** tab. Pick *Fast* or *Accurate* and the language
  (Auto, English, Arabic, Hindi, Urdu) at the top of the panel.
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

## 5. Where your things are

| Folder inside KBB Studio | What is in it |
|---|---|
| `workspaces/<company>/projects/` | your saved projects, one folder each (the videos you add are copied in). Projects save themselves. |
| `workspaces/<company>/exports/` | your finished MP4s |
| `library/music/` | the starter music (27 tracks, made for the studio, free to use) |
| `library/music/user/` | **your own music** — see below |
| `library/sfx/` | sound effects (28, free to use) |
| `brands/` | the brand kits: colours, fonts, logos, end cards (KBB, Planet Careers, and any company you add) |
| `models/` | the speech models, downloaded once by the setup |

`<company>` is `kbb`, `planet-careers`, or the short name of a company you added.

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
* The app name lives in `app/core/config.js` `APP_NAME` (and `appName` in `engine/main.go`, the launcher's file name).
