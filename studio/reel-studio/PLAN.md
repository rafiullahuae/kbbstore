# KBB Studio (Reel Studio) — plan (16 Nov 2026)

**Update, same day — the owner:** *"keep the name KK Studio [a typo, the owner then said: "KBB studio i mean"], but inside you can create an environment 'Planet Careers' and keep the related stuff there. and also allow me options to create any new company etc, and i will be able to switch between one to another. along with europeon countries, UK, Canada, AUS, etc and other major countries need to be covered in terms of images ads, related stuff and things needed for reels, flags, themes etc."* So: app name **KBB Studio** (one constant); **workspaces** = companies (seed KBB and Planet Careers; + New company; instant switch; each with its own brand kit, projects, exports, favourite recipes) stored under `workspaces/<slug>/`; **country packs** for Planet Careers (flags, themes, landmark silhouettes, stickers, spotlight recipes) covering Europe, UK, Canada, Australia, NZ, USA, Ireland, UAE and other major destinations; image-ad templates for Planet Careers in Ad Studio next.

**A SEPARATE PROJECT from the kbbstore Laravel website** (the owner, 16 Nov: "this kbb studio is a different project than KBB laravel website. don't mix it"). It lives under `studio/`, shares no code with the shop, is never part of a website update package, and never touches `app/`, `resources/`, `routes/`, `database/` or `public/`.

The owner: "i need with wide options like for a professional videos reels editors daily base needs ...
a seperate folder for it, as it will run only in my mac, completely offline. if anything we needed only,
the system should ask for permission to go live and fetch ... interface super friendly and instant use
while keeping the main functions in front ... a nice editor area, same like adobe premier or more easy
than that ... stunning ads reels, with wide range of pre-options ... or the system should auto suggest as
per the video, like unboxing, cooking recipe, consultation, offering, travelling and much more ... also
from the consultation industry as new separate company 'Immigration consultant' provides study visa,
immigration to different european countries etc ... and of course the KBB company will also be there."

Earlier the same day: auto captions in one click (editable), multiple caption styles, drag-select many
elements, auto short reel from the best parts, auto background music and effects, "guide it" automation.
Decisions taken for him (he did not pick letters): captions model B (more accurate, better Arabic),
music C + D (a built-in starter library AND his own tracks), typed requests = optional, online only after
he allows it.

## Shape of the thing

A folder he copies to his Mac: `KBB Reel Studio/`

```
KBB Reel Studio/
  Start Reel Studio.command     double-click: starts the local engine, opens the browser
  engine/reelstudio-mac         tiny Go server (universal arm64+amd64), 127.0.0.1 only, random free port
  app/                          the web app (HTML/JS/CSS, no build step needed at run time)
  vendor/                       third-party JS/WASM, pinned, offline (transformers.js, onnxruntime-web,
                                mediapipe tasks-vision, mp4 muxer/demuxer)
  models/                       speech + vision models, downloaded ONCE on first use after he says yes
  library/music, library/sfx    the built-in starter packs + whatever he drops in
  brands/                       brand kits: KBB, the immigration consultancy, more
  projects/                     his saved projects (one folder each: project.json + media links)
  exports/                      finished MP4s
```

Why a local engine and not a single HTML file: browsers refuse to load WASM/models from file:// and
cannot write projects/exports to a folder. The engine serves the app with the headers multithreaded
WASM needs (COOP/COEP), reads/writes only inside the folder, and is the ONLY thing that may touch the
internet — through `/net/*`, which refuses until the app has asked him and he said yes (per item, with
the size), and only to an allowlist (huggingface.co and its CDN for models). Unsigned binary: first run
is right-click → Open once (Gatekeeper); the README shows how.

Browser: Chrome (best: WebGPU + WebCodecs incl. HEVC from iPhone) or Safari 17+. Everything else runs in
the page.

## Modules and who owns them (lanes work in separate folders)

| Module | Folder | Lane |
|---|---|---|
| Engine (Go server, launcher, net gate, file API) | `engine/`, `Start Reel Studio.command` | R1 |
| Editor shell + timeline + preview + export + projects | `app/editor/`, `app/index.html`, `app/core/` | R1 |
| Captions: speech-to-text, caption model, caption editor, caption styles renderer | `app/captions/` | R2 |
| Analysis + automation: highlights, silence/filler cut, scene detect, reframe, beat detect, recipes, auto-suggest, music + SFX libraries | `app/auto/`, `library/` | R3 |
| Brand kits + recipe/template content | `brands/`, `app/auto/recipes/` | R3 |
| Optional online "describe your edit" (Claude API, his key, after permission) | `app/auto/ask.js` | later round |

### The contract every module codes to (`app/core/`, owned by R1, frozen once R1 commits it)

* **Project JSON** (`core/project.js`): `{ v, name, brand, canvas:{w,h,fps,bg}, media:[{id,kind,src,dur,w,h,
  fps,hasAudio}], tracks:[{id,kind:'video'|'overlay'|'text'|'caption'|'music'|'sfx',items:[...]}],
  captions:{style, lang, words:[{t0,t1,w,conf}], lines:[{id,t0,t1,wordIdx:[a,b]}]}, markers:[...] }`.
  Item: `{id, media?, t0 (timeline start s), in, out (source in/out s), x,y,w,h,rot,opacity, fx:[...],
  kf:{prop:[[t,v,ease]]}, text?, style?}`. All times in seconds (float), all geometry in canvas px.
* **Commands** (`core/history.js`): every change is `apply(cmd)` with undo/redo; modules never mutate
  the project directly.
* **Renderer hooks** (`core/render.js`): `registerLayer(kind, drawFn(ctx, item, t, project))` so captions
  and auto-effects draw in BOTH the preview and the export through one code path.
* **Media access** (`core/media.js`): `decodeAudio(mediaId) -> Float32Array mono 16 kHz + PCM 48 kHz`,
  `frameAt(mediaId, t, w) -> ImageBitmap`, `thumbs(mediaId)`.
* **Engine API** (`core/engine.js`): `listDir`, `readFile`, `writeFile`, `saveProject`, `export(path, blob)`,
  `askPermission({what, size, host}) -> bool` (the dialog is the app's; the engine only remembers the
  answer for this session), `fetchModel(repo, file) -> local path`.

R2 and R3 build against this contract with their own small test harness pages, and land in the editor in
the integration round. If R1 has not committed `core/` yet, they stub it from this text and R1's version
wins.

## The editor (R1) — "Premiere, but easier"

Layout: top bar (project, brand, aspect 9:16 / 1:1 / 4:5 / 16:9, Undo/Redo, **Export**); left: Media bin
(drag files in, thumbnails, durations) + Library tabs (Music, SFX, Stickers, Templates/Recipes); centre:
the preview monitor with safe-area guides, on-canvas editing (select, drag, resize, rotate, **marquee
multi-select, Shift/Ctrl-click, group, align/distribute, snapping**); right: Inspector for whatever is
selected (clip: speed, volume, fade, crop, colour/filters, zoom-in/Ken Burns; text/caption: style; shape;
sticker); bottom: multi-track timeline (video, overlays, text, captions, music, sfx) with playhead,
zoom, ripple delete, split (S), trim handles, snapping, marquee-select of clips, copy/paste, J/K/L and
space, markers, waveforms, track lock/mute/hide. Big friendly "Do it for me" buttons always in front:
**Auto captions · Auto reel · Remove silences · Add music · Suggest style**.

Home screen: New reel (pick brand + recipe or "Let it suggest"), Open recent, Drop video here.

Export: MP4 H.264/AAC (WebCodecs, hardware on Mac), 1080×1920 default, 30/60 fps, quality presets, plus
"also export 1:1 and 4:5". Frames-accurate: one render path for preview and export.

Speed: preview stays real time on a 2020+ Mac for 60 s 1080p with captions; scrubbing is instant from
proxy thumbnails; nothing blocks the UI (decoding/analysis in workers).

## Captions (R2)

* Speech-to-text in the browser: transformers.js + Whisper (ONNX), WebGPU when present, WASM otherwise.
  Models: **whisper-small** (default, better Arabic) and **whisper-base** (fast); downloaded once into
  `models/` after he allows it; word-level timestamps; language auto / English / Arabic / Hindi-Urdu.
* Lines built from words (max chars per line by style and aspect, never break mid-phrase, max 2 lines).
* Caption editor: transcript view (click a word → playhead jumps there), edit words, delete, add, split /
  merge lines, nudge timing, find & replace, emoji suggestions on keywords, profanity/filler highlight.
* **At least 16 caption styles**, each with live preview tiles: word-by-word highlight (box / colour /
  scale), karaoke fill, pop-in words, one-word-at-a-time big, bold outline, soft beauty (pastel pill),
  luxury serif, neon, typewriter, bounce, emoji accent, highlight marker, news lower-third, minimal
  subtitle, Arabic-first (RTL, proper shaping, Arabic fonts), bilingual (EN top / AR bottom). All editable:
  font, size, colours, outline, shadow, background, position (safe-area aware), animation, uppercase.
* Renderer registered as the `caption` layer so preview = export.

## Automation (R3)

* Audio analysis: loudness/energy curve, speech/silence (VAD), filler words from the transcript ("umm",
  "like", Arabic fillers), music beat grid (onset detection + tempo).
* Video analysis: scene cuts (frame histogram difference), motion energy, faces (MediaPipe face detector,
  small, offline) for **auto-reframe** 16:9 → 9:16 with smooth tracking, blur/shake/dark-frame rejection.
* **Highlight scorer** → **Auto reel**: pick the best moments to 15 / 30 / 60 s, keep whole sentences,
  hook in the first 2 s, cut on beats, end on the call-to-action.
* **Remove silences / fillers** in one click (with a review list).
* **Auto music**: mood from recipe + energy, auto-trim to length with clean ending, **ducking under
  speech**, fade in/out. Starter library generated OFFLINE (no licences to worry about): at least 24
  tracks across moods (luxury, upbeat pop, calm spa, lo-fi, corporate, Arabic-oud flavour, inspiring,
  travel) rendered to M4A/WAV by a deterministic synth here, plus his own tracks from `library/music/`.
* **Auto effects**: punch-in zooms on emphasised words, transitions on cuts (on beats), SFX (whoosh, pop,
  sparkle, ding, swipe, riser — synthesised offline, at least 20) on transitions and text pops, emoji /
  sticker accents, price/offer tag animation, logo intro/outro, payment logos end card for KBB.
* **Recipes** (the "guide it" layer): each recipe = structure + pacing + caption style + music mood +
  effects + overlays + end card. At least: **Beauty/KBB** — unboxing, product demo/texture, before/after,
  routine steps, offer/flash sale, UGC review, gift-with-purchase; **Immigration consultancy** — meet the
  consultant, study visa steps, country spotlight (Germany, Portugal, Poland, Italy, Malta, France, Spain,
  Netherlands, Ireland, Hungary, Czechia, Lithuania...), documents checklist, success story / client
  testimonial, FAQ / myth-buster, appointment booking CTA, webinar/seminar promo, deadline/intake alert;
  **Others** — cooking recipe, travel vlog, restaurant/cafe, real estate tour, fitness, event recap,
  tutorial/how-to, podcast clip, announcement. **Auto-suggest**: transcript keywords + scene stats (and,
  only if he allows the download, an offline image-text model) rank the recipes; he confirms.
* **Brand kits**: KBB (existing logo, colours, fonts from the shop/Ad Studio) and the immigration
  consultancy (placeholder name "Immigration Consultant" until he gives the real name/logo/colours —
  professional navy/teal/gold palette, EU-star motif, clean sans). Kits editable in the app.

## Rounds

1. **Now, in parallel:** R1 engine + editor + timeline + export; R2 captions; R3 automation + recipes +
   libraries + brand kits.
2. **Integration:** wire captions + automation into the editor's "Do it for me" buttons; full test pass;
   Mac folder zip; screenshots and a short screen recording; README for him.
3. **Then:** optional online "describe your edit" (permission + his Claude API key), more recipes.

## Quality bar for every lane

No console errors; every action undoable; nothing blocks the UI over 50 ms (workers); preview = export;
works with a 60 s 1080p iPhone HEVC clip and an Arabic voice-over; 390-wide layout not required (Mac
app), but 1280×800 and 1920×1080 must both be clean. Tests: a headless Chromium check script per module
(like Ad Studio's check-*.cjs). No network at run time except through the engine's permission gate.
