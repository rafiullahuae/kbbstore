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

## Brief → Designs (and Brief → Reel) — decided 16 Nov

The owner: *"if we need any ad design, in our own wording to explain, the system need to pick, analyse,
and design the ads accordingly ... i need professional work, not just basic designs"*, then: *"by default
no api, but keep option to connect the claude api, with super easy integration. but the offline version
must be capable of all complex tasks etc."*

* **Offline by default, and fully capable.** The brief is understood on the Mac with no account:
  1. a fast rule/keyword parser (occasions, % / AED offers, brands and products from the KBB catalogue,
     countries/visa types for Planet Careers, sizes, languages, tone, CTA) for everyday briefs;
  2. for free-form or complex briefs, a **small local language model running in the browser on the Mac's
     GPU** (WebGPU; e.g. a 1.5–3B instruct model, ~1–2 GB, downloaded once after he allows it), turning
     the brief into the same structured spec and writing EN/AR headline and offer copy variants. No
     internet after the download.
* **Design engine:** ~60 professional layout families per industry (beauty/retail; consultancy/
  education) + the existing 69 Ad Studio templates as smart layouts; design rules enforced and scored
  (hierarchy, grid, contrast ≥ 4.5:1 for text, safe zones, min text size, no product cropping, no
  overlaps); 6–8 distinct variants per brief across story/post/square; brand kit of the active company;
  products as cut-outs from the catalogue; country packs for Planet Careers; learns from kept/downloaded
  designs. Every result fully editable in the editor; full-HD/4K export.
* **Optional "Connect Claude"** (Settings → Connect Claude: paste key → Test → done). Off by default; when
  connected, a "Smart brief" toggle sends ONLY the brief text and the catalogue names it needs (never
  files or customer data) to the Claude API for richer understanding and copy; asks once per session;
  key stored locally by the engine, never in projects or exports; one-click Disconnect.
* **AI-generated visuals, offline — the owner, 16 Nov:** *"visuals should also come from the ai / auto
  generation etc. as per the industries or scope of work we have. like backgrounds, artwork, icons, color
  schemes etc."* So the studio generates its own visuals ON THE MAC, no account, no internet after a
  one-time permitted download:
  - **AI images** (backgrounds, scenes, textures, artwork) from a local image model on the Mac GPU
    (WebGPU ONNX, an SD-Turbo-class model, ~2 GB once; seconds per image), prompted automatically from
    the brief + industry + brand palette (e.g. "soft pink silk with petals, luxury beauty, UAE National
    Day accents"; "Toronto skyline at golden hour, clean, education"), plus a local upscaler to full HD;
    he can also type his own prompt and regenerate.
  - **Generated artwork and backgrounds without the model** (instant): gradients, mesh/blob, patterns,
    abstract shapes, frames, ribbons, confetti, light effects — procedural and always on-brand.
  - **Icons**: a large offline open-licence icon set (MIT/ISC, e.g. Lucide/Tabler) restyled automatically
    (stroke, fill, duotone, brand colours, badges), chosen by meaning from the brief.
  - **Colour schemes**: generated per brief from the brand kit, industry, mood, occasion and country
    flag colours, contrast-checked; save favourites to the company.
  - Generated images are checked like everything else (no text baked into images, product never covered,
    contrast with the copy) and stored in the company's library for reuse.
* The same brief reader drives **Brief → Reel** (recipe + copy + captions + music) so one brief can give
  matching ads and a reel.
* Round: after round 1 integration (editor + captions + automation).

## Models: one first-run setup, saved in the app folder — decided 16 Nov

The owner chose option 1 ("the 1 is fine, but no any errors should come while downloading, and the data
must be saved inside the app folder"). On first launch a Setup screen lists every model (captions,
brief language model, image model + upscaler, face detector) with sizes and free disk space, and
downloads them all after one "Allow" into `models/` inside the KBB Studio folder — never browser storage.
The downloader is built not to fail: disk-space check first, resumable `.part` files (HTTP Range) across
retries and restarts, automatic retries with backoff, size + checksum verification with re-fetch, atomic
rename, 2–3 files in parallel with progress/speed/time left, pause/resume, plain-English messages, and the
app never loads a model that is not complete. After setup the studio needs no internet.

## Round 2 — "the life-saving editor" — decided 16 Nov

The owner: *"go with all these, but must be super functional and to get output results. also include more
natural hooks in terms videos, sound etc. to grab attention of the viewer ... i need this studio a super
life saving editor/creator for all my ads, videos, edits etc stuff. plan it well."* All 27 items from the
viral-reels list are in, plus a natural-hooks library. Every feature must produce a finished, exportable
result in one click and stay editable; each is undoable; each has a check that renders/exports a real
output and measures it.

**Ownership (three lanes, separate folders; the editor gains panels only through the module registry —
any unavoidable edit to app/editor or app/core is kept minimal and listed in the lane's report):**

* **Lane V — Viral engine** (`app/viral/`): 1 hook builder (10 hook lines from transcript/brief, animated
  hook text, punch-in + sound hit, first-frame check, flash-forward "wait for it" opener); **natural hooks
  library** — video hooks (pattern interrupts: snap zoom, freeze-frame + record scratch, whip-in, reveal
  mask, "POV:" / "Nobody tells you…" text hooks, question hooks, number hooks, before-after flash, object
  toss transition, face-close-up open) and sound hooks (riser, hit, swoosh, bass drop, notification ding,
  camera shutter, record scratch, crowd "ooh", ASMR taps/pours/sprays for beauty textures, ambient
  airport/plane-boarding for travel, page flip, stamp thud for "visa approved"), all synthesised offline
  and tagged by mood/industry; 2 retention pacing score + dead-zone fixes; 3 jump-cut tightening
  (breaths/gaps < 0.3 s); 4 smart zooms on stressed words + face-in-upper-third reframe; 7 safe-zone check
  per platform (IG Reels, TikTok, YT Shorts, Snapchat) with auto-nudge; 8 loop endings; 9 CTA builder
  (comment-keyword, link in bio, save/share, DM, call/WhatsApp) animated per brand; 18 auto chapters /
  step counters / progress bar; 23 A/B variations (3 hooks/openers); 26 claim-safety for Planet Careers
  ("guaranteed visa", "100% approval" → compliant wording) and for beauty ("cures", medical claims).
* **Lane L — Look & sound** (`app/look/`, `app/audio/`): 10 pro audio — offline voice cleanup (noise
  suppression model, e.g. RNNoise/DeepFilterNet-class WASM), levelling, de-ess, EQ presets, platform
  loudness targets, SFX on cuts, beat-synced cuts, riser before reveal; 12 transitions (whip, match-cut,
  zoom-through, object-cover, flash, glitch, slide, spin, luma fade) + speed ramps with easing; 13 colour
  looks (LUT/shader presets, auto white-balance/exposure, shot matching); 14 light skin/face touch-up
  (WebGL, face-landmark guided, subtle); 15 background removal for people (offline segmentation) with
  flag/landmark/brand backgrounds; 16 text-animation presets (typewriter, kinetic stack, highlight sweep,
  price slam, countdown, checklist ticks), branded per company; 17 Arabic-first motion presets.
* **Lane C — Content & growth** (`app/content/`, plus caption looks inside `app/captions/styles-extra.js`
  only): 5 viral caption looks (active-word colour, 1–3 words at mid-third, keyword/emoji pops,
  face/product-avoiding placement); 6 auto b-roll from his own library (auto-tagging of media on import —
  offline image tagger, optional model — plus keyword/catalogue match, KBB product shots, Planet Careers
  country assets); 11 proven formats as ready timelines (before/after split & wipe, "3 products for…",
  GRWM, texture close-up, dupe-or-worth-it; visa-approved reaction, documents in 30 s, myth vs fact,
  country A vs B, cost breakdown, testimonial, day-in-the-life); 19 series kits (opener, episode number,
  look); 20 cover/thumbnail maker (best frame, title, grid-readability check, 3:4 grid cover); 21 caption,
  keywords, hashtags and comment prompts (offline local language model; EN/AR); 22 one edit → 9:16/4:5/
  1:1/16:9 re-laid out (not just cropped); 24 long video → 8–12 shorts (webinar/consultation), each with
  hook + captions + CTA; 25 teleprompter + record from the Mac camera/mic in the studio + best-take
  picker; 27 trending-sound cue sheet (beat markers kept so his IG sound lines up).

Then **Round 3 — Brief → Designs** (local image model, artwork, icons, colour schemes) and **Connect
Claude** (optional), as already planned.

## Captions round — queued 16 Nov (starts the moment a round-2 lane frees up)

The owner: *"need to work on the captions capturing more, like option to remove the music text when music
comes etc. and give me the full script also after detecting the captions. i need also more options for
captions templates, and also can be edit the style / text / size etc in case needed. don't stop on
anything work on everything."*

* Capture more: stronger speech detection (VAD-gated decoding, re-read of missed speech, overlap stitching
  checks), lower drop rate in noisy/music sections, punctuation and casing, numbers/currency/visa terms
  vocabulary (brand + product names from the KBB catalogue, country/visa terms for Planet Careers).
* Non-speech handling: Whisper's "[Music]", "♪", "(applause)", "[Laughter]", hallucinated repeats and
  "Thanks for watching" in silence are detected; options: **Hide music/sound tags** (default on),
  **Show a ♪ icon instead**, **Keep as text**; captions auto-hide while music plays with no speech.
* **Full script** after detection: a Script view (paragraphs, speaker turns if detectable, timestamps
  optional), editable and synced back to captions, export TXT / SRT / VTT / DOCX-free plain formats and
  copy-to-clipboard; EN and AR (and bilingual side by side).
* **More caption templates**: 30+ more, grouped (Viral, Minimal, Luxury, Bold, Corporate, Arabic,
  Bilingual, Fun), searchable, favourites per company.
* **Edit anything**: per-line and per-word overrides of text, size, colour, font, position, animation
  and emphasis, on top of the global style; drag a caption on the canvas to move it; reset to style.

## Work Abroad, image-first — queued 16 Nov

The owner: *"not just for study abroad, i need work abroad also, for different occupations, and i don't want
generic text ad. i want to focus more on the images, specially the pictures of the workers, employees in
specific uniform etc, like plumber, teacher, helper, drivers, nurse, and other all occupations."*

* **Occupation library** (data, EN/AR): 80+ occupations — trades (plumber, electrician, welder, carpenter,
  mason, painter, AC/HVAC technician, pipe fitter, steel fixer, scaffolder, crane/forklift operator),
  transport (light/heavy driver, bus driver, delivery rider, warehouse), care & health (nurse, caregiver,
  elderly care, doctor, pharmacist, lab tech), hospitality (chef, cook, kitchen helper, waiter, barista,
  housekeeping, hotel front desk, cleaner), services (security guard, salesperson, cashier, beautician,
  barber, tailor), education (teacher, nanny/au pair), agriculture/food (farm worker, fruit picker,
  butcher, baker, meat/fish processing), industry (factory/production, packing, machine operator,
  mechanic, auto electrician), office/IT (IT, engineer, accountant, admin) — each with uniform/PPE
  description, tools, colours, icon, keywords, and which countries commonly recruit (editable).
* **Image-first Work Abroad templates (40+, Ad Studio, 3 sizes)**: big worker photo or cut-out as the
  hero (uniform visible), occupation title, destination flag/landmark scene behind, "We're hiring",
  "Work in Poland as a welder", job card, duo/trio collage of occupations, uniform close-up, team shot,
  benefits/salary/visa-type badges as EDITABLE placeholders (never invented numbers or promises),
  apply/WhatsApp CTA; a reel recipe per family too. Occupation picker swaps photo, title, tools/icons and
  accents in one click (like the country picker).
* **Where the worker photos come from:**
  1. **Stock photo search inside KBB Studio** (on his Mac, online only when he searches, after
     permission): Pexels and Unsplash (free commercial licences), with his own free API keys entered once
     in Settings; results saved into `library/people/<occupation>/` with the licence and photographer
     recorded; filters for people, orientation, region/ethnicity keywords.
  2. **Offline AI-generated worker photos** (the local image model from Brief → Designs): prompts built
     from the occupation library (uniform, tools, setting, region), several candidates per click,
     regenerate; best at portrait/half-body; obvious flaws (hands/tools/text) rejected or flagged.
  3. **His own photos** of real workers/clients (best for trust), auto-tagged by occupation.
  Background removal (round 2, Lane L) cuts any of these out onto country scenes.

**Built 16 Nov (Work Abroad lane):** `library/occupations/` (100 occupations EN/AR, uniforms, tools + 106 icons, 10
figure families, photo search terms, AI prompt template, suggested countries) · Ad Studio: 44 image-first templates
(11 families × 3 sizes, `src/templates-wa.js`), Occupation picker beside Country, worker photo slots + offline ✂ cut-out
(`ad-studio-cutout.js`), `tools/check-occupations.cjs`, sheets `shots/work-abroad-*` · engine: Pexels/Unsplash hosts behind
the per-URL gate, keys in `settings/` (Go tests) · `app/stock/` 📷 Photos tab (`tools/stock-check.cjs` against
`tools/stock-mock.cjs`, `shots/stock/`) · six recipes in `app/auto/recipes/work-abroad.js`. Still to come: source 2 (the
local image model -- the prompts are ready in occupations.json).

## Round 3a — translation, animated themes, hover previews — started 16 Nov

The owner: *"i need the capability to translate cross caption script ... english ... to urdu, arabic etc.
with one click it should translate auto and apply in captions, same vice versa. i need super accuracy ...
daily basis"*, *"animations to use in videos over the ad like if video is related to europe, the europe
theme ... throughout ... super nice animations, themes etc. across each industry"*, and *"from the
template, the preview should play upon mouse hover, so i can be sure before import to the project."*

* **Translation lane** (`app/captions/translate/`): one-click caption translation EN↔AR/UR/HI and more,
  replace / bilingual / second track; offline models chosen by measured accuracy on a real ad-sentence
  test set (permissive licences only — no NLLB), glossary/do-not-translate per company, numbers and
  prices preserved, sentence-level translation re-split to caption timing, per-line quality flags,
  side-by-side review, per-language exports; "Best quality (Claude)" only through Connect Claude.
* **Themes lane** (`app/themes/`): 30+ animated themes that run through the whole video (Europe and each
  country pack, travel, study, work abroad, beauty, luxury, offers, seasonal, corporate, food, fitness,
  real estate), face- and caption-safe, beat-reactive, brand-coloured, with matching intro/outro,
  transitions, text and SFX; auto-suggested. Plus hover-to-play previews on every gallery (templates,
  formats, themes, looks, transitions, music/SFX).
