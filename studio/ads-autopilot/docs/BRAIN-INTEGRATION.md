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

## Engine gaps found while integrating — closed by LANE ENGINE, 20 Nov 2026
1. ~~No per-proposal approve endpoint~~ → `POST /api/proposals/{id}/approve|reject` (+ `GET /api/proposals`); Approvals uses them.
2. ~~No route serving a creative's stored PNG/MP4~~ → `GET /api/creatives/{id}/file` (account-scoped); the library shows real pictures.
3. ~~`video_path`~~ → `upload_creative {format: video, video_path: "videos/<id>.mp4"}`; the adapter maps the brain's `path`, its video copy and `video:<id>` creative ids.
4. ~~`/api/limits` refuses `ad_wording`~~ → `/api/limits` takes `wording: strict|balanced|relaxed`; `brain.Wording()` reads it there first (the brain's own setting is the fallback).
5. ~~`-mock` Claude answers text only~~ → scripted `respond` / web-search answers (`engine/metamock/claude.go`): planner, copy, research, trending and vision run end to end in `-mock`.

## Checks
- `cd engine && go test ./...` (brain: unit + adapter tests with a fake `model.LLM`).
- `tools/brain-mutate.sh` — 29 decision/linter/video/trending mutations, all must be killed.
- `NODE_PATH=… node tools/brain-ui.cjs [--real] [--shots]` — 12 checks against the fake engine (3 accounts, fixtures) or the REAL engine `-mock`.
- `python3 -I tools/e2e-real-brain.py` — the real brain + mock Meta (3 accounts) + scripted mock Claude, first week end to end; `tools/engine-mutate.sh`; `tools/engine-ui.cjs`.
