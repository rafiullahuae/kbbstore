# LANE BRAIN — what it ships and how it plugs into the engine

## Wiring (done)
- `engine/brain_wire.go`: `newBrain` returns `brain.New(d)` (the engine's own Go tests and `KAA_BRAIN=stub` keep `brainstub`, because they test the executor against the stub's proposals).
- `engine/api.go`: one line in the default branch calls `s.brainExtra(...)`, which mounts the brain's routes under `/api/brain/x/` behind the same token / origin / account checks.
- The brain uses `engine/model` through `engine/brain/adapter.go` (both directions). Every Claude call goes through `model.LLM` (engine/claude): structured answers are a strict client tool `respond`; web search is `LLMRequest.WebSearch`.

## Brain routes (`/api/brain/x/…`, account-scoped)
`cycle` (verdicts, rich plan, proposals, designs to render, copy sets, profile, economics) · `design-done` · `designs` (archive) · `copy` (AR+EN copy + design specs) · `settings` (`ad_wording` strict|balanced|relaxed, `promo_approved`) · `profile` · `insights` (30-day series, AR/EN split) · `research` (rich, AR+EN) · `trending` (GET / POST refresh) · `videos` (GET, POST raw upload, `videos/{id}/analyse`, `videos/{id}/thumb`, PUT) · `img?u=` (same-origin kbeautybliss.com photos for the canvas).
State kept in `<data>/brain/` (small JSON) and videos in `<data>/videos/`.

## What the ENGINE executor must support (formats the brain proposes)
| Brain format id | `upload_creative.format` | Needs |
|---|---|---|
| single_image | image | creative_id |
| single_video / reels_stories / whatsapp_status / partnership | video | creative_id of a video creative, or **`video_path`** (owner's video under `<data>/videos/`, param `video_id`) — *engine: accept `video_path`* |
| carousel | carousel | 2–10 product_ids → cards |
| collection | collection | canvas_id (Instant Experience) |
| catalog | catalog (campaign `type: catalog`) | catalog_id, product_set_id |
| flexible | flexible | creative_ids[] |
| click_to_whatsapp / click_to_messenger | image + `destination` whatsapp / messenger / instagram_direct | linked WhatsApp number / IG account |
Ad sets: `advantage_audience: 1`, `genders: [2]`, `age_range: [18,45]` for the women presets, `destination`, placements omitted = Advantage+ placements (an explicit Reels/Stories list only when the planner asks). Targets of new objects are `tmp:<name>`.

## Engine gaps found while integrating
1. No per-proposal approve endpoint: the Approvals screen's "Approve" runs `POST /api/cycle {mode:"auto"}` for the whole cycle.
2. No route serving a creative's stored PNG/MP4: the library shows creatives from the brain's design archive (re-rendered) or as text tiles.
3. `upload_creative` for the owner's own videos needs a `video_path` (file in the data folder) instead of base64.
4. `/api/limits` refuses unknown fields, so the brain keeps `ad_wording` itself (`/api/brain/x/settings`).
5. `-mock` Claude does not answer with tool calls, so in `-mock` the planner/vision fall back to rules-only / local-only (labelled in the UI).

## Checks
- `cd engine && go test ./...` (brain: unit + adapter tests with a fake `model.LLM`).
- `tools/brain-mutate.sh` — 29 decision/linter/video/trending mutations, all must be killed.
- `NODE_PATH=… node tools/brain-ui.cjs [--real] [--shots]` — 12 checks against the fake engine (3 accounts, fixtures) or the REAL engine `-mock`.
