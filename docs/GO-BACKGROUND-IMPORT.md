# The import that keeps going with the tab closed · Lane GO

> *"import also batch by batch. with real progress and with have pause or stop
> button. i want this job must be running in background, even i close the tab."*

---

## 1. Three of those four clauses were already true

Before anything was written, each clause was checked against what ships:

| The owner asked for | Already true? | Where |
|---|---|---|
| "batch by batch" | **yes** | `ImportDriver::step()` — one bounded slice per HTTP request, `ImportRunner`'s `limit` used as a *resume point*, each batch's rows and its checkpoint committed in one transaction |
| "real progress" | **yes** | Lane GF — the bars take their denominator from `manifest.json` and the numerator from `import_checkpoints`, so the number survives a killed request |
| "pause or stop button" | **yes, in the browser** | `#impPause` stops the console's loop; `#impStop` posts `/import/stop` |
| **"running in background, even i close the tab"** | **NO** | the loop that calls `/import/step` is `impDrive()` in `app.blade.php`. It lives in the tab. Closing the tab ends the import. |

So this lane built one thing, and the other three were left alone.
`ImportApiController`, `ImportWorkspace`, `routes/import-admin.php` and
`resources/views/admin/app.blade.php` are untouched, and the browser-driven run
behaves exactly as it did — `AdminImportScreenTest`'s 29 tests are what says so,
and they are unchanged and passing.

**One exception, and it is worth reading before the rest:** three lines of
`ImportDriver` gained a `where('status', 'running')` on their bookkeeping
update. It is a pre-existing race that the rehearsal turned up — a slice
finishing after Stop put the run back to `running` — and it is the one thing
here the integrator should look at specifically. §16.

---

## 2. The mechanism, and why this one

### Nothing runs on this host unless a request runs it

No shell, no cron, no queue worker, no supervisor. `App\Services\OutboundTick`'s
header states the consequence in full and it is the premise here too: `queue:work`
is not merely inconvenient, there is nothing on this server that could execute
it. And the brief forbids requiring the owner to add a cron entry through a
hosting panel.

So the question is not *"how do we run a background job"* but **"what makes the
next request happen when there is no browser to make it"**. There is exactly one
answer available on such a host: **the server asks itself.**

### A self-chaining relay

```
  admin presses "Keep importing in the background"
        │
        │  POST /admin-api/import/background        (auth:admin)
        ▼
  ImportChain::begin()  ── mints a baton, stores only its SHA-256
        │
        │  loopback HTTP POST, baton in a request header
        ▼
  POST /import-chain/continue          ← no session, no cookie, no browser
        │
        ├─ claim()  one conditional UPDATE: check + consume + rotate
        ├─ 204, empty body        ← the caller hangs up here and its process exits
        └─ defer(): one slice of the import, then the same loopback call again
                 │
                 ▼
           …and again, until the import is finished, stopped, or bounded
```

**Each link answers 204 *before* it does any work.** That is what makes the chain
a **relay** rather than a **stack**: this request's response goes out while its
caller is still alive, the caller's process then exits, and only then does the
slice start. If the work came first, every link would hold its predecessor open
and a twenty-minute import would be a twenty-minute-deep nest of live PHP
workers — on shared hosting, an exhausted pool inside a minute. Measured: two
concurrent connections at the hand-over instant, never three
(`docs/go-background-shots/`, server log).

`defer()` and not `app()->terminating()`, for the reason `OutboundTick`'s header
gives: terminating callbacks are never cleared off the `Application` and fire a
second time the moment one process handles two requests. Here that would be two
slices from one baton.

### This is not an invention

It is what `wp-cron.php` has done on every shared host for fifteen years,
including — and this is the part that matters — **on the WordPress installation
this shop is being migrated off**. The mechanism is already known to work on the
owner's hosting, because his old shop depended on it.

### What was rejected

| Rejected | Why |
|---|---|
| **A queued job** | Nothing would run it. `QUEUE_CONNECTION=sync` executes it inline, which is the browser-driven run with extra steps. |
| **A tick on the tail of every request** (`OutboundTick`'s shape) | Right for ten emails, wrong for an import slice. `OutboundTick`'s own budget note says a sweep runs "in a PHP-FPM worker that other requests are waiting for"; a slice is seconds of database writes. It would also make the import's speed a function of how many strangers are browsing — on a shop that has not launched, none. What *is* borrowed from it is `reviveIfStalled()`: a cheap, conditional kick, never a slice. |
| **An external pinger** (cron-job.org) | Works, and makes the import depend on a third-party account the owner must create, must remember, and will not notice has lapsed. The brief forbids requiring hosting-panel configuration; this is the same requirement in a different hat. |
| **Replaying the admin's session cookie** on the loopback call, so the continue endpoint could stay inside `auth:admin` | Storing a live session credential on the server for hours, replayable by anything that can read it, and dead the moment the owner logs out — which is one of the things "I closed the tab" often means. A single-use baton is strictly less powerful and strictly more robust. |

---

## 3. What was built

| File | What it is |
|---|---|
| `app/Services/ImportConsole/ImportChain.php` | the engine: the baton, the bounds, the loopback call, the state machine, the lean progress payload |
| `app/Http/Controllers/ImportChainController.php` | the one endpoint that continues an import with no session behind it |
| `app/Http/Controllers/Admin/ImportBackgroundController.php` | start / pause / resume / stop, the poll, and the page |
| `routes/import-background-admin.php` | four admin routes, inside the existing `auth:admin` group |
| `routes/import-chain.php` | the one public route, at top level in the `web` group |
| `resources/views/admin/import-background.blade.php` | the live page the owner opens |
| `database/migrations/2026_11_25_000000_add_import_background_chain.php` | ten additive columns on `import_runs` |
| `database/migrations/2026_11_25_000001_clear_caches_import_background.php` | the `clear_caches_*` that ships with any new route |
| `tests/Support/ImportBackgroundRoutes.php` | mounts both route files the way the integrator is told to |
| `tests/Feature/GoBackgroundImportTest.php` | 52 tests, 382 assertions |

Endpoints:

```
GET  /admin-api/import/background          the bars and the chain state, lean
GET  /admin-api/import/background-page     the live page — the owner's URL
POST /admin-api/import/background          carry this run on without a browser
POST /admin-api/import/background-control  {action: pause|resume|stop}

POST /import-chain/continue                the loopback call itself
```

### No new capability rule

`AdminCapabilities::RULES` already carries `['*', 'admin-api/import/**',
'data.import']`, and `**` matches across segments. **A prefix of its own would
have fallen through to the closed owner-only default.** That was established by
asking `AdminCapabilities::forPath()` rather than by reading the table — a rule
shadowed by an earlier wildcard is dead text and reading the table cannot see
that:

```
POST  admin-api/import/background          => 'data.import'
GET   admin-api/import/background          => 'data.import'
GET   admin-api/import/background-page     => 'data.import'
POST  admin-api/import/background-control  => 'data.import'
POST  import-chain/continue                => NULL   (and correctly so — §5)
```

A test of this lane's own asks the same question of every route it registered,
so a future tidy-up that narrows the wildcard fails in the suite rather than as
a 403 on a host with no shell.

---

## 4. Exactly one chain, and it is one SQL statement

Two chains racing is the failure that matters. Both read the same checkpoint,
both import the same rows, both advance it — and the offset ends up past work
nobody did, with totals that still look right.

`ImportDriver`'s existing per-slice `locked_at`/`lock_token` claim stops two
slices **overlapping**. It does not stop two chains **alternating**, which would
run every slice twice and take twice as long to be wrong.

The baton does. `ImportChain::claim()` is one conditional UPDATE:

```sql
UPDATE import_runs
   SET chain_token = <hash of a brand-new secret>, chain_beat_at = now(),
       chain_expires_at = now() + 600, chain_slices = chain_slices + 1
 WHERE run_key = 'admin'
   AND chain_token = <hash of the secret presented>
   AND chain_expires_at > now()
   AND status = 'running' AND background = 1 AND paused = 0
```

That one statement is, simultaneously:

* **the credential check** — the row matches only for a caller holding the secret whose digest is stored;
* **the replay guard** — presenting it consumed it, so the same secret matches nothing ever again;
* **the mutual exclusion** — two callers presenting the same secret in the same instant produce one affected row and one zero, on every store this application runs on;
* **the stop check** — `status = 'running' AND paused = 0` is evaluated *at the moment of the claim*, so a chain in flight cannot continue past a Stop pressed a millisecond earlier.

It is deliberately **not** split into a read, a `hash_equals` and a write. Each
piece would be individually readable and the set of them would have a race
between the check and the consumption — which is the one bug this whole design
exists to prevent.

**No `hash_equals()` in the claim, and that is not an oversight.** The column
holds a SHA-256 digest, not the secret. A timing side channel on the database's
own byte comparison could at most leak the digest, and a digest is not a
credential — forging a request still needs its preimage, and the preimage is 256
bits from `random_bytes()`. A PHP-side `hash_equals` in front of the UPDATE
would compare the same digests a second time and guard nothing the UPDATE does
not already guard: exactly the dead text CLAUDE.md records this repository
paying for twice. (`hash_equals` **is** used, once, where it is load-bearing:
the "have I been superseded" check in `slice()`, which compares a digest this
process is holding against the one in the table.)

### A crash cannot leave a lock held

**There is no held lock to leave.** The baton is rotated at *claim* time, before
any work: the new secret exists only in the memory of the process now working.
If that process is killed, the secret dies with it and the token in the table is
one nobody holds. Nothing is wedged and nothing is blocked — the chain simply
stops, the heartbeat goes cold, and `state()` reports **STALLED**.
`chain_expires_at` then retires the orphan digest on its own.

The cost is honest and is on the screen: **one killed slice ends the chain.** It
is the right trade. The alternative — a lease another process may seize once it
looks old — is a design in which two processes can both believe they hold it,
and that is the duplicate-import bug with a timer on it. `reviveIfStalled()`
picks the run back up instead, safely, by going through `begin()` like anybody
else.

---

## 5. Continuing is authorised without a session

`POST /import-chain/continue` is the only route in this application that touches
the importer and is not inside `auth:admin`. **It cannot be**, and not for
convenience: it is called by this server, with no browser, no cookie and no
session, *because the owner closed the tab*. There is no session for it to be
inside. So the session is replaced by a credential.

| Requirement | How |
|---|---|
| **not guessable** | 256 bits from `random_bytes()`, hex-encoded. Only its SHA-256 is stored; the secret exists in the memory of the process that minted it and in one request header. |
| **not replayable** | `claim()` matches and replaces the digest in one statement. A secret that reaches this endpoint twice works once. |
| **not replayable into a second chain** | same statement. A captured baton spends the chain's next link; the real chain's own call is then refused and the page says STALLED. It cannot produce a chain running *beside* the real one. |
| **never in a URL** | header only — `ImportChainController` reads `$request->header()` and nothing else, and `kick()` posts to a bare URL with no query and no body. So it is never written into this host's access log, a proxy's, or any `Referer`. A test asserts the secret appears in no part of the URL or body. |
| **never forwarded** | `withoutRedirecting()`. Guzzle follows redirects by default and carries custom headers across the hop, so a host that 301s to another name would hand the secret to that name. |
| **exists only while a run does** | an admin, signed in, pressed a button to create it. There is no baton at rest. |
| **decides nothing** | not the entity, not the row count, not the options, not the mode. Every one of those is read from the run row an authenticated admin wrote. The body is never looked at. A caller holding a valid baton can make the owner's own import go one step forward and can do nothing else at all — it cannot start one, change one, or read one. |
| **answers nothing** | 204 or 404, both with an empty body. No status, no counts, no file names, no refused rows. There is no version of this endpoint that leaks customer data. |

### Why always the same 404

A forged baton, a spent one, an expired one, a stopped run, a paused run, a
finished run and a shop that has never imported anything all return the
identical empty 404 after the identical work. That is CLAUDE.md's own rule about
`Api\QuizController::expertRequest` applied one endpoint over —
`findByPublicToken()` looks the row up *before* it checks the signature so that
a forged token and an id that was never issued do the same work and return the
same answer, and the note is explicit that branching differently restores the
oracle.

**The cost is paid by us, not by an attacker.** The legitimate caller cannot tell
"this host did not route the request" from "the baton was refused" either. So
`kick()` reports *what it tried* rather than claiming to know why, and the
sentence the owner reads names both possibilities, including the stale compiled
route table.

### Why `web.php` and not `api.php`

`routes/api.php` is the obvious home: unauthenticated by design, and it is where
`routes/payments-webhooks.php` puts the precedent for exactly this shape — a
server calls us, a secret authorises it, and money moves. It was rejected.
CLAUDE.md's `/api/*` landmine is that **every** endpoint there is public and that
three leaked customer columns; the standing instruction is to allowlist what a
model returns before putting it there. Adding an endpoint that *drives an import*
to that file invites the next reader to treat it as one more public read.

At top level in the `web` group it is a named, two-segment path whose own route
file says what it is, carrying `SecurityHeaders` and the session middleware it
simply does not use.

**CSRF is excluded at the route, not in `bootstrap/app.php`.** The caller has no
session and therefore no token, so inside the `web` group every call would be a
419. `payments-webhooks.php`'s header names the two ways out — `routes/api.php`,
or `validateCsrfTokens(except:)` in `bootstrap/app.php` — and both are closed
here: the first for the reason above, the second because `bootstrap/` is on
`BuildPackage::NEVER_SHIP`, `UpdateGuard` forbids it outright, and a change there
would have to be hand-applied to the server exactly like the `usePublicPath()`
line. A route-level exclusion ships in the package and applies to one path.

**Two segments, and that is load-bearing.** The last route registered in
`web.php` matches a single path segment at the site root — the shape of every
storefront URL — so a one-segment path would have to be added to
`PageController::RESERVED_SLUGS` as well. Two segments cannot be reached by it,
and `RootSlugCollisionTest` keeps that true.

---

## 6. Stop means stop. So does Pause.

| | What it does | Where it bites |
|---|---|---|
| **Stop** | `ImportDriver::stop()` — the existing one, untouched — then `ImportChain::halt()` | `status = 'running'` is inside the claim, so a chain in flight is refused the moment it comes back for its next link. `halt()` additionally drops the baton, so a stopped run holds no secret. |
| **Pause** | `paused = 1`, baton dropped, `status` left at `running` | `paused = 0` is inside the claim. The run is still the run; Resume picks it up. |
| **Resume** | `paused = 0`, then straight through `begin()` | `begin()` is the *only* place a baton is minted, so there is no second path by which a chain can come into existence. |

A slice **already executing** when Stop is pressed finishes and commits its
batch. That is correct and deliberate — the batch's rows and its checkpoint
commit together, so aborting mid-batch would throw away work that was about to be
accounted for. What must not happen is a *further* slice, and that is guaranteed
twice: the process re-reads the run after its slice and does not kick, and the
far end would refuse the call anyway.

**Resuming lands on the row after the last committed one.** That is not this
lane's claim to make — `import_checkpoints.processed` is written inside the same
transaction as the rows it counts, which is Lane FV's property, rehearsed there
against eight SIGKILLs. What this lane owns is that pause and resume do not
disturb it, and there is a test that runs two slices, pauses, resumes, and
asserts the offset is unchanged at 8 and that the next slice starts at row 9.

---

## 7. A runaway is worse than a stall

Four bounds, any one of which ends the chain, each recorded in `chain_note` so
the screen can say which:

| Bound | Value | Why |
|---|---|---|
| `MAX_SLICES` | 5000 | the counted bound. A chain gets this many links and then asks to be started again. |
| `MAX_CHAIN_SECONDS` | 6 hours | the wall-clock bound, for a chain whose slices are tiny. |
| `MAX_FAILS` | 5 consecutive | a chain that is not making progress must stop, not spin. |
| the owner | Stop, Pause | checked inside the claim. |

And when it ends, **it says so.** `halted` is a state of its own, distinct from
`running` and from `stalled`:

```
RUNNING   the server is importing and the last piece came back recently
STALLED   a piece did not come back. It is picked back up automatically
HALTED    the chain stopped on purpose, and the note says why
PAUSED    the owner pressed Pause
IDLE      a run exists but a browser is driving it
FINISHED  the run is over
```

Deliberately the same vocabulary as Lane GD's media page, so the two read as one
system rather than two features that both happen to have a bar.

**The ordering of those checks is itself a guard.** `chain_token === null` is
tested *before* the heartbeat, because a chain halted one second ago has a
*fresh* heartbeat. Testing the heartbeat first would report it as RUNNING for the
whole stale window — precisely the bar frozen at a stale number that this state
machine exists to prevent. Mutation **M12** is that reordering, and it goes red.

---

## 8. The defect the rehearsal found, before the rehearsal

`STALE_SECONDS` (240) is **less** than `ImportDriver::LOCK_SECONDS` (300), and
that gap was a real bug.

A slice killed by the host's execution limit leaves `ImportDriver`'s own
per-slice claim held, because the `finally` that releases it never ran. That
claim ages out by itself after `LOCK_SECONDS` — it is not a wedge — but until it
does, `step()` refuses every caller with *"this import is already being worked
on"*.

So a revival fired between t+240s and t+300s would be refused, would count as a
failed slice, and would chain straight into the next one, which would be refused
too. **Five polls of the progress page — fifteen seconds — would burn the whole
`MAX_FAILS` budget and HALT a run that was about to be perfectly resumable.**

Two candidate fixes were weighed:

1. **Raise `STALE_SECONDS` above `LOCK_SECONDS`.** Safe, and it means the page
   reports a dead chain as RUNNING for five and a half minutes. That is the
   silent half-success this feature is supposed to end.
2. **Report STALLED honestly at 240s, and have the *revival* wait for the
   driver's claim.** Chosen. `reviveIfStalled()` reads `locked_at` and declines
   while a claim younger than `ImportDriver::LOCK_SECONDS` is held, and the
   stalled note tells the owner it will pick itself up and when.

It reads `ImportDriver::LOCK_SECONDS` rather than a number of its own, so the two
constants cannot drift apart. Mutation **M13** removes the guard and goes red.

---

## 9. When the host refuses all of it

A shared host that will not open a connection to itself — no loopback, DNS that
resolves the domain somewhere this machine cannot route to, an outbound firewall
— cannot run a chain, and no code here can change that.

**The first kick is the preflight.** There is no separate probe, because a probe
that succeeds and a chain that then fails is two chances to be wrong about the
same question. `begin()` fires the first kick synchronously and, if it does not
come back 204:

* **everything is put back** — `background` cleared, no baton, `status` left at
  `running`, checkpoints untouched. The console's Continue button carries on
  exactly as before, and a test asserts a real browser-driven step still works
  immediately afterwards;
* **the owner gets a sentence he can act on**, naming what was tried, the address
  it was tried at, the browser-driven fallback by the name of its button, and the
  stale compiled route table — because a 404 from an un-cleared
  `bootstrap/cache/routes-*.php` looks exactly like a host that refuses loopback,
  and would tell the owner his hosting cannot do this on hosting that can.

**Two attempts, in order.** The shop's own address; then the same URL with
cURL's own resolver override forcing `127.0.0.1`. Shared hosts routinely publish
a public IP for their own domains that the machine itself cannot route to — the
classic "the site cannot fetch its own pages" fault. The resolver override is the
right tool rather than rewriting the URL to `http://127.0.0.1`, because the URL,
the `Host` header and the TLS name all stay correct. **TLS verification is not
turned off** for it; a certificate that does not match is a real fault and is
reported as one. The second attempt is skipped when the shop already answers on
a loopback address, so one failure is never printed as two lines of the same
failure.

**A database without the migration** reports `state: unavailable` with a sentence
saying so, `begin()` refuses in words, and `claim()` returns null — so there is
no path to a slice at all. Everything else keeps working.

---

## 10. Watching a background run from a fresh tab

`GET /admin-api/import/background-page` is a standalone HTML document, for the
three reasons Lane GD's page gives and which apply here word for word: it must
be trustworthy at the moment something is broken, so it must not depend on the
20,000-line console that might *be* what is broken; `package.json` defines no
`build` script and CI does not build assets, so anything needing compilation
would ship broken; and polling, not sockets, because shared hosting — three
seconds while something is going, fifteen when it is not, and nothing at all
while the tab is hidden.

**The poll is not `/import/status`.** That call opens and counts the rejection
CSV and rebuilds the whole file list, and this page polls for as long as an
import takes — `ImportDriver::denominators()` already carries this exact
reasoning. `ImportChain::progress()` returns the bars and the chain state and
nothing else.

**The revival hangs off that poll**, and the placement is the feature rather than
a convenience. A chain dies when the process holding its baton is killed, and
nothing on this host notices, because nothing on this host runs by itself. So the
act of *looking* revives it: opening the page picks a stalled run back up, and a
tab left polling picks it up within one poll.

**The honest limit, stated on the page and not only here:** if a slice is killed
while every tab is closed, the run stays stalled until somebody opens the shop's
admin. Nothing is lost, nothing is duplicated when it resumes, and the page says
STALLED with the reason. On a host with nothing that can run a timer, that is the
floor.

---

## 11. The rehearsal

Against a real server (`php -S`, eight workers, the front controller from
`public-web-root/` whose parent holds `bootstrap/` and `vendor/`,
`SESSION_DRIVER=file`), logged in with `curl`, importing a real export.

### 11.1 · 20,000 rows with nothing attached

`docs/go-background-shots/1-unattended-run.log.txt` — the driving connection was
closed and the session file moved away the instant the POST returned. The watcher
is **a separate process that opens the sqlite file and nothing else**; it cannot
drive anything.

```
--- POST /admin-api/import/background at 12:33:53 ---
ok=true via=the shop's own address msg=The import is now running on the server. You can close this tab.
>>> connection closed, session taken away at 12:33:53. Nothing is driving this now.

  0.00s  brands=0      processed=-      status=running   slices=0   rows/slice=400   baton=held
  0.20s  brands=200    processed=200    status=running   slices=1   rows/slice=400   baton=held
  0.41s  brands=400    processed=400    status=running   slices=2   rows/slice=640   baton=held
  ...
 13.90s  brands=19939  processed=19939  status=running   slices=8   rows/slice=10746 baton=held
 14.11s  brands=20000  processed=20000  status=complete  slices=8   rows/slice=17194 baton=gone · the import finished
RUN IS OVER: complete
```

Eight self-chained links, no client, and the slice size found the host's pace by
itself: 400 → 640 → 1024 → 1639 → 2623 → 4197 → 6716 → 10746. The server log
shows the loopback as a real TCP connection accepted while the driving one was
still open, and never more than two at once.

### 11.2 · A slice killed mid-flight

`docs/go-background-shots/2-killed-mid-slice.log.txt`. An 80,000-row export, the
whole server process group `SIGKILL`ed five seconds in — the host cutting a
request off, with no chance to run a `finally`.

```
>>> SIGKILL at 12:39:48
  5.04s  brands=7526   processed=7526   status=running   slices=6   rows/slice=4197  baton=held
>>> and now, with the server gone entirely:
status=running background=1 paused=0 slices=6 fails=0 rows/slice=4197 baton=held lock=2026-09-19 12:39:47
  processed=7526 finished=no brands=7526 distinct=7526
```

`processed` and the rows in the table agree exactly, and every slug is distinct:
**the checkpoint never ran ahead of the work.**

### 11.3 · …and picked back up with nobody pressing anything

`docs/go-background-shots/3-picked-back-up.log.txt`. The server was restarted and
then **left alone**: the only thing touching the application is the progress
page's own poll. No button was pressed.

```
server back at 12:40:02. The killed slice left this behind:
status=running background=1 paused=0 slices=6 fails=0 rows/slice=4197 baton=held lock=2026-09-19 12:39:47
  processed=7526 finished=no brands=7526 distinct=7526
  note=-

From here nothing exists but this page's own poll. Every 10 seconds:
  t+0   s state=running  slices=6   beat=15  s rows=7526  /80000  (9%)
  t+11  s state=running  slices=6   beat=26  s rows=7526  /80000  (9%)
  …
  t+223 s state=running  slices=6   beat=238 s rows=7526  /80000  (9%)
  t+233 s state=stalled  slices=6   beat=248 s rows=7526  /80000  (9%)
  t+284 s state=stalled  slices=6   beat=299 s rows=7526  /80000  (9%)
  t+294 s state=running  slices=1   beat=0   s rows=7526  /80000  (9%)
  t+304 s state=running  slices=3   beat=3   s rows=22139 /80000  (28%)
  t+345 s state=running  slices=8   beat=4   s rows=77369 /80000  (97%)
  t+355 s state=finished slices=8   beat=8   s rows=80000 /80000  (100%)
  >>> the run is complete at t+355s
FINAL:
status=complete background=1 paused=0 slices=8 fails=0 rows/slice=10746 baton=gone lock=free
  processed=80000 finished=2026-09-19 12:45:49 brands=80000 distinct=80000
  note=the import finished
```

**RUNNING for 240 seconds, then STALLED, then picked up by itself.** Nothing was
pressed. The page's own poll is the only thing that touched the application. At
t+294s — once the dead slice's `locked_at` had aged past `ImportDriver::LOCK_SECONDS`
— the revival fired, `slices` reset to 1 because it went through `begin()` like
anybody else, and the run finished.

**80,000 rows, 80,000 distinct.** It resumed at 7,527 and no row was imported
twice or skipped.

### 11.4 · Two chains, deliberately

Driven in the suite rather than by hand, because the property being demonstrated
is about what happens at *every single link* and a hand-run can only sample it —
`it imports each row once when two chains are set going deliberately` holds one
baton the way a chain in flight would, starts a second chain on top of it, runs
that one to the end, and presents the held baton at every link along the way.
Result: the held baton is accepted **zero** times, every row lands exactly once,
and no row is skipped — which is the failure that would not show up in a total.

### 11.5 · The screens

`docs/go-background-shots/*.png`, captured in Chromium against the running
server through a real admin login. Every state the page can be in has a shot,
because the whole argument of §7 is that they must not look alike:

| Shot | What it shows |
|---|---|
| `1-idle-browser-driven.png` | a run exists and a tab is driving it — **IDLE**, with the offer to hand it over |
| `2-running-in-background.png` | **RUNNING** on the server: pieces done, rows per piece, "you can close this tab" |
| `3-stalled.png` | **STALLED**, banner and all, with the bar turned red and the sentence saying it picks itself back up |
| `4-halted.png` | **HALTED** on a runaway bound, quoting which bound and what to press |
| `5-paused.png` | **PAUSED** |
| `6-finished.png` | **FINISHED** |
| `7-host-refuses.png` | the refusal, with the address it tried and the stale route table named. The run behind it is untouched and still says IDLE. |

`7-host-refuses.png` was produced by moving the loopback route out from under
its own path and rebooting — which is exactly what a server with a stale
compiled route table looks like, and is the reason that sentence names the route
table at all.

### 11.6 · Stop during a background run

`docs/go-background-shots/4-stopped.log.txt`.

```
=== Stop, pressed while the server is importing on its own ===
background: ok=true via=the shop's own address
>>> pressing Stop at 13:18:19
stop: ok=true state=finished · Stopped. Nothing it had already imported was undone.
>>> what a separate process saw across the Stop:
  7.06s  brands=10026  processed=10026  status=running   slices=6   rows/slice=4197  baton=held 
  7.26s  brands=10326  processed=10326  status=running   slices=6   rows/slice=4197  baton=held 
  7.47s  brands=10523  processed=10523  status=stopped   slices=6   rows/slice=6716  baton=gone · stopped from the progress page
RUN IS OVER: stopped
>>> eight seconds later, still nothing moving:
status=stopped background=1 paused=0 slices=6 fails=0 rows/slice=6716 baton=gone lock=free
  processed=10523 finished=no brands=10523 distinct=10523
  note=stopped from the progress page
```

Rows stopped arriving within one slice, the baton went, the note is the true
one, the run stayed `stopped` — and eight seconds later nothing had moved.

**This rehearsal found the two worst defects in the lane,** and both are fixed,
tested and mutated:

* its first run reported *"This host will not let the shop call itself"*. The
  Stop had landed in the window between a process deciding to kick and the far
  end answering; the far end refused the baton — correctly, the run had just
  been stopped — and that 404 was read as a hosting fault and written over the
  true note. Everything BEHAVED correctly. The owner would have been told his
  hosting could not do this, on hosting that had just done it, and pointed at
  the one remedy that could not help. `slice()` now writes a loopback failure
  only when it is still the current chain of a still-running run. (M26, M27.)

* and eight seconds after that Stop, the run row read `status = running` with
  `processed` 2,297 rows further on. The slice that was in flight finished and
  `ImportDriver::liveStep()`'s bookkeeping put the status back. **§16.** (M28.)

Neither was reachable from the suite as it stood, and neither would have been
found by reasoning about the code — both are races measured in the milliseconds
between two real HTTP requests.

---

## 12. Mutation testing

Every guard was removed, one at a time, and the lane's test file re-run. The
harness is `mutate.py` (in the scratchpad, not shipped): it applies exactly one
replacement, asserts the anchor occurs **once**, runs the suite, and restores the
file from a backup in a `finally`.

| # | What was removed or broken | Result |
|---|---|---|
| M1 | `claim()` no longer compares the baton at all | RED |
| M2 | `claim()` no longer replaces it, so it can be replayed forever | RED |
| M3 | an expired baton is still accepted | RED |
| M4 | **stop flag, 1 of 2** — `claim()` drops `status = 'running'` | RED |
| M5 | **stop flag, 2 of 2** — `claim()` drops `paused = 0` | RED |
| M6 | `claim()` drops `background = 1`, so a browser-driven run can be advanced by a baton | RED |
| M7 | the counted runaway bound (`MAX_SLICES`) is gone | RED |
| M8 | the wall-clock runaway bound (`MAX_CHAIN_SECONDS`) is gone | RED |
| M9 | **the lock, 1 of 2** — the chain no longer re-reads the run after a slice | RED |
| M10 | **the lock, 2 of 2** — a superseded chain hands its stale baton on anyway | RED |
| M11 | a chain that cannot make progress spins forever (`MAX_FAILS`) | RED |
| M12 | `state()` tests the heartbeat before the baton, so a halted chain reads as running | RED |
| M13 | `reviveIfStalled()` races the dead slice's own claim | RED |
| M14 | `reviveIfStalled()` revives a healthy run too, producing a second chain | RED |
| M15 | the loopback follows redirects, carrying the baton to another host | RED |
| M16 | the baton goes in the query string, where an access log keeps it | RED |
| M17 | a failed first kick leaves the run flagged background with no chain | RED |
| M18 | `pause()` leaves the baton in the table | RED |
| M19 | `issue()` stores the secret instead of its digest | RED |
| M20 | the endpoint reads the baton out of the request body | RED *(green on the first pass — §12.1)* |
| M21 | the 404 carries a header saying which case it was | RED |
| M22 | the slice runs before the response, so the chain nests | RED *(green on the first pass — §12.1)* |
| M23 | CSRF is no longer excluded, so the server cannot call itself | RED |
| M24 | the loopback route is put behind the admin session it can never have | RED |
| M25 | the second loopback attempt is never made, so split DNS just fails | RED |
| M26 | a Stop pressed mid-kick is reported as the host refusing loopback | RED |
| M27 | a genuine loopback failure is no longer reported at all | RED |
| M28 | a slice finishing after Stop puts the run back to running | RED *(green on the first pass — §12.1)* |
| M29 | the live page strips one path segment instead of two and polls a 404 forever | RED |

**29 mutations, 29 red.** The lock and the stop flag were each done twice, from
two different sides, because each has two guards and a mutation of one alone is
shadowed by the other — which is exactly how M5, M6 and M9 survived the first
pass.

### 12.1 · The six that survived a pass, and what each one taught

They are reported rather than quietly fixed, because the point of the exercise is
what it finds. All six are now covered and all six go red.

**Shadowed by a second guard — the failure mode CLAUDE.md names by name.**

* **M5 (`paused = 0` in the claim)** and **M6 (`background = 1` in the claim)**
  stayed green because `pause()` *also* drops the baton, so the ordinary pause
  test passes with the clause deleted: the token is gone anyway. Two guards,
  one of them dead text as far as any test could see. Now covered by two tests
  that set the flag and **deliberately leave the baton in place**, each of which
  asserts up front that the baton really is still there — so a future change that
  starts clearing it cannot silently disarm them again.
* **M9 (the status re-read after a slice)** stayed green because the background
  page's Stop also halts the chain, so the "have I been superseded" check fired
  first and the status re-read was never reached. Now covered by a test that
  uses the **console's own** `/import/stop`, which knows nothing about the chain
  and leaves the baton alone.

**Hidden by the test harness itself.**

* **M22 (the slice running before the response)** stayed green because the
  ordinary test helper calls `terminate()` for you, which hides the difference
  between deferred and not deferred. Now covered by a test that drives the
  kernel by hand: after `handle()` the response is 204 and the baton is taken
  but **no** checkpoint row exists; only after `terminate()` has anything been
  imported.
* **M28 (a slice finishing after Stop putting the run back to running)** stayed
  green because the first version of its test stopped the run *before* calling
  the step — which makes `step()` refuse at its own front door, so
  `liveStep()` never runs and the line under test is never reached. Now covered
  by a test that fires the Stop from a query listener on the importer's own
  first `INSERT`, genuinely between `step()` starting and its bookkeeping
  landing.

**Asserted about the wrong end.**

* **M20 (the endpoint accepting the baton out of the request body)** stayed green
  because the "never in a URL" test asserted that `kick()` does not *send* it in
  one — which is not the same as the endpoint refusing to *read* one. A
  well-meaning future caller could have put it in a query string and every test
  would still have passed. Now covered by a test that presents the real secret
  in the query string and in three body fields at once, requires a 404, and then
  proves the secret was good by using it from the header.

---

## 13. What the integrator has to add

Three files this lane may not edit. Every anchor below was verified by count
against the file as it stands at `7ac72b3`; each says whether it appends or
replaces.

### 13.1 · `routes/web.php` — two requires (both **append after** the anchor)

**Anchor 1** — occurs **once**, inside the existing `admin-api` group (the one
carrying `auth:admin` and `NoStoreAdminApi`):

```php
        require __DIR__.'/import-history-admin.php';
```

**Append immediately after it:**

```php

        // Store → Import → the run that keeps going with the tab closed (Lane
        // GO). Same group and the same reason as the two files above: pressing
        // this makes the shop rewrite its own catalogue, customer list and
        // order history, unattended, for as long as it takes.
        require __DIR__.'/import-background-admin.php';
```

**Anchor 2** — occurs **once**, at top level, after the last storefront require
and before the Phase 9 catch-all:

```php
require __DIR__.'/checkout-card.php';
```

**Append immediately after it:**

```php

/*
 * The loopback call that keeps a background import going (Lane GO). Top level
 * in the web group, NOT inside admin-api and NOT in routes/api.php: it is made
 * by this server with no session, because the owner has closed the tab, and its
 * own file's header is the argument for every part of that. It excludes CSRF at
 * the route because the caller has no token to carry. Two path segments, so the
 * Phase 9 root-segment catch-all below cannot reach it — but registered before
 * it all the same.
 */
require __DIR__.'/import-chain.php';
```

### 13.2 · `resources/views/admin/app.blade.php` — three edits

Without these the feature is still reachable, at
`<admin-api>/import/background-page`, and the console is unchanged. These put a
button on the screen the owner already uses.

**Anchor A** — occurs **once**. **Replace** it (the `+'</div></div>'` line is
kept so the block still closes):

```js
    +(busy?'<button class="btn ghost" id="impPause">Pause</button>'
          :'<button class="btn" id="impRun">'+(isLive&&run.status==='running'?'Continue import':'Import')+'</button>')
    +'</div></div>'
```

**with:**

```js
    +(busy?'<button class="btn ghost" id="impPause">Pause</button>'
          :'<button class="btn" id="impRun">'+(isLive&&run.status==='running'?'Continue import':'Import')+'</button>')
    +(isLive&&run&&run.status==='running'?'<button class="btn ghost" id="impBackground">Keep importing in the background</button>':'')
    +'</div></div>'
```

**Anchor B** — occurs **once**. **Append immediately after it:**

```js
  const stop=$('#impStop'); if(stop) stop.onclick=async()=>{ impRunning=false; await impApi('/import/stop',{method:'POST'}); await impRefresh(); impPaint(); };
```

```js

  /* Lane GO. Hands the run to the server and opens the live page, which is
     where Pause, Stop and the real bars then live. The browser loop is stopped
     first: leaving it going would be a second driver, and the chain's baton
     exists precisely so there is only ever one. A refusal is shown here rather
     than on the page, because a host that will not call itself means the owner
     stays on THIS screen and keeps pressing Continue. */
  const bg=$('#impBackground');
  if(bg) bg.onclick=async()=>{
    impRunning=false;
    const r=await impApi('/import/background',{method:'POST',body:'{}'});
    if(!r.data||r.data.ok===false){
      impMsg=(r.data&&r.data.message)||'Could not hand this over to the server.'; impMsgKind='bad'; impPaint(); return;
    }
    window.open(impBase()+'/import/background-page','_blank');
    impMsg='The server is carrying this on by itself. You can close this tab.'; impMsgKind=''; impPaint();
  };
```

**Anchor C** — occurs **once**. The sentence is now only half true, and the half
that is false is the half the owner asked about. **Replace:**

```js
    +'<div class="impnote">Your browser does this in small pieces, a few seconds at a time, so this shared server never has to hold one long request open. '
    +'You can close this tab: whatever had finished stays finished, and coming back here offers to carry on. It will not start over and it will not import anything twice.'
```

**with:**

```js
    +'<div class="impnote">Your browser does this in small pieces, a few seconds at a time, so this shared server never has to hold one long request open. '
    +'Closing this tab stops it here — whatever had finished stays finished, and coming back offers to carry on from the row after the last one. '
    +'To have the server keep going with the tab closed, press “Keep importing in the background”.'
```

---

## 14. What still needs one real run on the owner's host

Everything above was proven on a real HTTP server on this machine. **One thing
cannot be**, and it is the one thing the whole feature rests on:

> **Will `easywebsol.com` open a TCP connection to itself?**

The answer is almost certainly yes — his shop is a WordPress installation and
`wp-cron.php` works the same way — but "almost certainly" is not a thing to ship
silently. So:

1. Apply the package (both migrations; the `clear_caches_*` one **must** run, or
   `/import-chain/continue` will not exist and the feature will report the host
   as refusing loopback on a host that does not).
2. Upload one small export. Press **Import**, then **Keep importing in the
   background**.
3. **Either** the button reports *"The import is now running on the server. You
   can close this tab."* — in which case close it, walk away, come back to
   `<admin-path-api>/import/background-page` and the bars will have moved;
4. **or** it reports what it tried, and the browser-driven Continue button is
   still there, still works, and nothing has changed. That is the designed
   outcome of a refusal, not a failure to handle one.

Two smaller things the rehearsal could only approximate:

* **The host's real execution limit.** The chain finds its own slice size from
  what it measures, exactly as the console's loop does, and halves on a failure.
  On this machine it climbed to 17,000 rows a slice; on shared hosting it will
  settle far lower, and that is the mechanism working.
* **The number of PHP workers.** The relay needs **two** concurrent workers for a
  moment at each hand-over. Every FPM pool has more; a pool of one would
  deadlock, and is not a configuration this host can have (it would already be
  unable to serve a page with an image on it).

---

## 15. Landmines noted for whoever comes next

* **`STALE_SECONDS` must stay below `ImportDriver::LOCK_SECONDS`, and the
  revival must keep reading `LOCK_SECONDS`.** §8 is the whole story. The two
  numbers are not independently chosen.
* **The order of the checks in `state()` is load-bearing.** `chain_token === null`
  before the heartbeat. See §7.
* **`claim()` must stay one statement.** Splitting it for readability puts a race
  between the check and the consumption.
* **The baton must stay in the header.** M20 is the mutation; the test that
  catches it presents the real secret in a query string and three body fields.
* **The slice must stay inside `defer()`.** M22 is the mutation; the test that
  catches it drives the kernel by hand because the ordinary helper hides it.
* **`ImportDriver::liveStep()`'s bookkeeping update must keep its
  `where('status', 'running')`.** M28. See §16.
* **The live page's API base strips two path segments, matched by name.** M29.
  Getting it wrong is a page that renders its frame and then sits empty forever,
  which is not an error anybody sees.

---

## 16. The one change to a file this lane did not write

`app/Services/ImportConsole/ImportDriver.php` — three updates gained
`->where('status', 'running')`. **The integrator should look at this one
specifically**, because it is Lane AD's and Lane GF's file and this lane is
otherwise entirely additive.

It is a pre-existing race, not one this lane introduced. The row `liveStep()`
writes at the *end* of a slice carried `status => 'running'` unconditionally, so
a Stop pressed *during* that slice was set straight back to running when the
slice finished. In the browser-driven run that was survivable — the console's
loop also stops in the tab, so nothing continued and the wrong status was only
ever a wrong label. **In a run with no tab attached it is not survivable**, and
it is precisely the clause this lane's headline rests on.

It was found in the rehearsal, by pressing Stop on a real background run and
reading the run row seven seconds later: `status = running`.

The bookkeeping in that update goes with the status, and that is correct rather
than a loss: a stopped run is continued by pressing Import again, which is
`start()`, which writes `done_entities`, `started_entities`, `baselines` and
`notes` fresh. The rows the slice committed are untouched — they committed with
their checkpoint and are correctly accounted for. The fix is about the status,
not about throwing work away.

The same guard went on the preview step's update and on the "everything is
finished" update, for the same reason and because leaving one of three
unguarded is how this comes back.

---

## 17. Test inventory

`tests/Feature/GoBackgroundImportTest.php` — **52 tests, 382 assertions**, in ten
groups matching the sections above:

1. where the routes mount and what guards them (7)
2. the baton: forged, replayed, expired, concurrent, header-only, silent (11)
3. exactly one chain, including a full unattended relay run (4)
4. stop, pause, resume, and the row the resume lands on (8)
5. the runaway bounds (4)
6. stalled, and picking it back up — including the window in §8 (4)
7. when the host refuses (3)
8. the browser-driven run is untouched (2)
9. what the screen reads, and the page's own address (5)
10. a database that has not had the migration (2)

Plus `tests/Feature/AdminImportScreenTest.php` (29 tests, unchanged and passing),
which is what pins that the browser-driven run still behaves as it did.
