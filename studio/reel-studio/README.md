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
* `tools/` — `int-media.cjs` + `int-check.cjs` (end-to-end), `check-r1.cjs`, `check-models.cjs [--real]`,
  `r2-check.cjs`, `r3-check.cjs`, `package-mac.sh` (→ `dist/KBB Studio.zip`, models excluded).
* The app name lives in `app/core/config.js` `APP_NAME` (and `appName` in `engine/main.go`, the launcher's file name).
