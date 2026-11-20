# QA findings — 20 Nov 2026 (QA lane)

## For the owner: do the keys survive replacing the app folder?

**Yes.** Your keys are never in the app folder. They are in the macOS Keychain (service "KBB Ads Autopilot", one
item per key), and the list of which keys you saved (names and dates, never the keys) is in
`~/KBB Ads Autopilot Data/keys-index.json`. Replacing the folder, an update from Settings → Updates and a roll back
all leave both untouched.

A new version of the engine cannot be locked out of them either. The engine never opens the Keychain itself: it asks
Apple's own `/usr/bin/security` tool, and the keys are saved **without** `-T` (which would tie them to one engine
build) and **without** `-A` (which would let any app read them). Apple's rule is that an item made this way trusts
the program that made it, which is `/usr/bin/security`, the same on every Mac and every version of this app. So the
new engine reads them without asking (Apple: `man security`, add-generic-password `-T` / `-A`).

What could still make a key look gone, and what it says now:

- **macOS asks and you click Deny or ignore the prompt, the login keychain is locked, or the engine runs without a
  logged-in screen.** The key shows "macOS didn't let the app read its saved keys… click Always Allow, then Try
  again", never "not set". Before today, a cycle in that state said "no Meta token saved" in Activity. It now says
  the saved token could not be read.
- **You deleted it in Keychain Access.** The key shows "saved on <date> but no longer in the macOS Keychain".
- **Running a test copy (`-mock`) on your Mac** used to write its fake keys over your real ones in the Keychain.
  That is fixed: test runs keep their fake keys in their own folder.

Checked on Linux with a stand-in for `security` that answers the way macOS does (found, not found 44, cancelled 128,
not allowed 36, locked 51, and a save that reports success but did not happen), across replacing the folder, an
in-app update and a roll back (`engine/secrets/replace_test.go`). The real engine was also stopped, its folder
replaced with a fresh build and started again (`tools/qa-journey.cjs` R1–R7: keys, accounts, limits, plan,
creatives and history all still there).

## Fixed in this lane (each with the test that goes red without it)

| Bug | Fix | Test |
|---|---|---|
| `-mock` wrote EAAMOCK… / sk-ant-mock over the owner's real Keychain keys | `setupMock` uses the data folder's file store | `TestMockNeverWritesTheOwnersKeychain` |
| An unreadable key showed up as "no Meta token saved" / "no Anthropic key saved" in every cycle | `secrets.Key` + `TokenErr` / `KeyErr` on the Meta, Claude and shop clients | `TestUnreadableKeyIsNotNoKeySaved`, `TestClaudeErrorsSayWhatToDo` |
| `security -i` exiting 0 although the add failed meant "saved" for a key that was never stored | `Set` reads the key back | `TestKeysSurviveFolderReplacement` ("silent") |
| A settings file the engine could not read was treated as never set, said nothing, and the next save wrote the defaults over it | `store.Load` keeps `<name>.json.unreadable`; config/settings failures go to Activity | `TestUnreadableSettingsAreKeptAndSaid` |
| A failed `/api/accounts` at start sent him to the first-run wizard (`.catch(() => ({accounts: []}))`); a failed `/api/connections` left the skeleton forever | a clear message with Try again | `qa-journey.cjs` S1/S2 |
| Account switch not recorded by the engine: swallowed | toast | (UI, by reading) |
| Claude errors read "Claude 529 overloaded_error: Overloaded" | each status says what to do and that nothing changed | `TestClaudeErrorsSayWhatToDo` |
| Meta offline at launch: the scheduler never resumed (the daily re-test ran only if Meta was already OK) | warm-up with backoff retry and a retry on `online` | `TestWarmUpWaitsWhileOffline` |
| Research notes never refreshed by themselves (`brain.Due` said "the scheduler calls Research"; it never did) | warm-up step "research" when older than 72 h | `TestWarmUpAfterNineHourSleep` |

## For the STRATEGY lane (not edited here)

1. **`engine/starter.go:339`** `cr, _, _ := ac.creatives.Get(crID)` ignores the error. If the creative cannot be read
   back, the upload proposal goes out with an empty headline and primary text. Expected: return the error from `addAd`
   so the starter reports "skipped: <why>" in Activity.
2. **`engine/brain/adapter.go:67-75` `save`** ignores every error: MkdirAll, Marshal, WriteFile, Rename (and no fsync).
   On a full disk the brain's per-account settings (ad wording, creation mode), creation requests and trending are not
   saved, and nothing says so. **`load` (line 61)** returns false on a JSON error, which the callers read as "never set",
   so the next `save` writes defaults over the owner's choices. This is the same class as the engine bug fixed in
   `store.Load`. Expected: keep a `.unreadable` copy and `e.log("alert", …)` on both.
3. **`engine/brain/adapter.go:120-128`**: a failed daily trending refresh is dropped without a log line ("never blocks
   a cycle" is right, but say it). Expected: `e.log("research", "trending refresh failed: …")`.
4. **Creation requests and occasions are only re-evaluated inside a cycle** (`brain/creation.go:115
   creationAfterCycle`). The warm-up re-evaluates them only when a cycle is due. Expected: an exported
   `(*brain.Engine).Refresh(ctx, acct, now)` that recomputes "due soon" and occasion requests without planning. The
   warm-up (`engine/warmup.go`, step "cycle") will call it when no cycle is due.
5. **`brain/design_test.go:247` `TestGeneratorsCostFirstBudgetAndKeys` is flaky** (red about 1 run in 5 here: "video
   start 200 … state:done"). It is probably the package-level `genS` job map shared across tests.
6. **`engine/brain/generator.go:189`** `secrets.New(e.dir, nil)`: the generator keys (OpenAI, Google, fal…) are not
   registered for audit-log redaction (`onValue` nil), unlike the engine's keys (`st.AddSecret`). A provider error that
   echoes the key would reach `log.jsonl` in clear. Expected: pass a redaction hook through `model.BrainDeps`.

## Silent failures left as they are (low risk, listed)

- `token.go:149` `_ = a.sec.Set("meta_token", long)`: a refreshed 60-day token that cannot be saved is dropped silently.
  The old token keeps working until it expires, and the 7-day warning still fires.
- `app/js/screens/updates.js:186`: Cancel ignores a failed `/api/update/cancel`. Nothing is installed either way.
- `app/js/create/remote.js:18`: a render result POST that fails is dropped. The connector's own timeout reports it.
- `app/js/create/create.js:49`: a failed session list reads as "no session", so a resumed Create box starts fresh.
- `mc.AppSecret` (`app.go`) ignores a Keychain read error. Meta then refuses without `appsecret_proof` and says so.

## Robustness (by test or by reading)

- **Meta**: rate limits, the hour block, an expired token and a policy rejection are covered by `engine-check.py`
  (injections) and the `meta` package tests. No duplicate writes: creates carry `X-Client-Idempotent-Key`, and the
  ledger is persisted before the write (`executor/execute.go:166`), so an engine killed mid-cycle does not replay a
  create.
- **Claude**: 401, 403, 429, 529, other 5xx, no credit and offline each give a plain message. 429, 5xx and 408 are
  retried twice (4 s, 8 s); the next cycle tries again.
- **Shop API down**: the products still show; the error says why orders are missing (`shop.Snapshot`).
- **No internet / Mac sleep and wake**: the warm-up waits and retries, and runs no cycle on stale data.
- **Two tabs**: shared SSE; a second tab or a reload does not re-run the warm-up (`qa-journey.cjs` W5).
