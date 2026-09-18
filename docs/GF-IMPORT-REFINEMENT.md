# The import, refined · a bar with a real denominator, a repeat the shop
# recognises, and a record that outlives the run

Lane GF. The owner's words:

> *"for the import export. i want it super refined without any error, and with
> super live bar and all record, an avoid duplicate import option too."*

Three things and a standard. Lane GE is building the WordPress plugin at the
other end of the same pipe; this is the importer side, and
`docs/WP-EXPORT-CONTRACT.md` — the integrator's file, unchanged by this lane —
is what the two ends meet on.

---

## 1. The short answer

| the ask | what shipped |
|---|---|
| **a super live bar** | `manifest.json`'s `rows` is now the denominator. Every present file gets a bar; the whole export gets one bar of its own, which nothing had before; and the live progress page's catalogue stage, which deliberately drew **no** bar at all, now draws one per entity. **Five separate conditions each suppress a bar rather than draw a wrong one** — §3. |
| **avoid duplicate import** | The shop recognises an export it has already read and **refuses it with a sentence** before the owner waits several minutes, with a deliberate override. A **partly** imported export is never refused, and a **corrected re-export is never refused** — that is the case a careless guard gets exactly backwards. §4. |
| **all record** | `import_history`: one row per entity per run, which export it came from, taken from where and when, created / updated / unchanged / refused / adjusted / discarded, the notes and the verification line. It survives the next run and it survives Reset. There is a page. §5. |
| **without any error** | Seven defects found in the import path and fixed, five more found and written up rather than fixed. §6. |

**47 tests, 1,447 assertions** in `tests/Feature/GfImportRefinementTest.php`.
**28 mutations**, every guard broken and restored; **four did not go red first
time and all four are reported below**, with what each one turned out to be. §7.

Driven for real: a 4,042-row export imported through `ImportDriver` in **38
browser steps**, then the same export refused, then a corrected re-export
accepted. Screenshots in `docs/gf-import-shots/`. §8.

Both suites green on the whole repository, not only on this lane's file:
**4,527 passed on MySQL** (`KBB_TEST_DB=kbb_gf`, 15 skipped) and the same set on
file-based SQLite, plus `php -l` across `app database routes`.

---

## 2. The manifest, and what the contract gets right that is worth saying

`App\Services\ImportConsole\ImportManifest` is a reader for
`docs/WP-EXPORT-CONTRACT.md` and nothing else. Every rule in it is quoted from
the contract in the comment beside it. **Nothing in the contract needed
changing**, and the two things worth telling the integrator are both
observations rather than objections:

1. **`rows` excluding the header is the rule that makes the mismatch check
   work.** `ImportWorkspace::countRows()` has counted data rows — `fgetcsv`, not
   newlines, so a description with a newline in it is one row — since Lane AD.
   The two counts are computed by different code at different ends of the pipe
   and they have to agree exactly, or every export would report a disagreement.
   They do. If the plugin ever counts the header, the shop will tell the owner
   his upload is short by one on every file.

2. **"Absent from `files` is not `rows: 0`" earns its keep immediately.**
   `lists()` and `rowsFor()` are two different questions here, and a mutation
   that answered the first with the second (M3/M27) was wrong on exactly one
   case: a file the plugin listed but could not count. That case is not
   hypothetical — the contract also says unknown keys are ignored, which means a
   partial entry has to survive.

Two things the reader does that the contract leaves to the implementer, stated
here so the plugin end knows what to expect:

* **A byte-order mark is tolerated.** `json_decode` returns null on a BOM, and a
  BOM is what an editor leaves on a file somebody opened to look at. The
  manifest is not wrong; the editor was.
* **An unreadable `rows` means "no count", never `0`.** `0` has a meaning the
  contract gives it and must not double as "I could not read this", or a
  header-only file and a corrupt entry would draw the same bar.

### A manifest this shop cannot read stops the import, including a preview

The contract says `format` is checked and anything else "is refused with a
sentence naming what was found, not a stack trace". It is, at two points: at
**upload**, where the owner is still looking at the file he just chose, and at
**Start**, for a manifest replaced out of band (which on a shared host is what
an FTP client does).

It is a refusal and not a shrug because a manifest written to a format this shop
does not speak describes a *file set* this shop may not read correctly either —
the counts it would be trusted for, the digests the duplicate guard matches on,
and the column names the importers parse all come from the same plugin. **There
is no tick box to override it**, deliberately: "ignore the thing you do not
understand" is not a decision anybody can make from that screen. The escape
hatch is one press and is named in the sentence — remove `manifest.json`, and
the import runs exactly as every export before the plugin existed ran.

---

## 3. The bar, made honest

### What `rows` actually buys, which is not what it looks like it buys

The contract says `rows` "settles" the denominator the catalogue stage never
had. That is **true of the live progress page** — `MigrationProgress::catalogue()`
reads `import_checkpoints`, which records rows consumed and nothing about how
many there are — and it is **not true of the Import screen**, which has drawn a
per-entity bar off `ImportWorkspace`'s own count since Lane AD
(`app.blade.php:7929`). Saying otherwise would have been this lane accepting
credit for a bar that was already there. So, honestly, `rows` adds three things:

1. **A denominator for a stage that reads tables rather than files.** The
   catalogue stage draws bars now — §8's shot 4.
2. **A denominator for the whole export.** Nothing had one. "1,648 of 4,042 rows
   across 9 files" is a sentence the screen could not previously say.
3. **And the one that matters most: a denominator that can be WRONG, and
   therefore one that can be checked.** A count taken off the file can never
   disagree with the file, so it cannot notice that the file is half of what the
   exporter sent — it draws a confident bar that reaches 100% over a truncated
   upload. `rows` comes from the other end of the pipe. `rows != counted` is a
   real fact about a real accident, and the shop says so and draws no bar.

### The five ways a bar is suppressed, each a way it would otherwise lie

`ImportDriver::status()` returns `percent: null` — and every renderer treats
null as "no bar, and say why" — when any of these is true:

| condition | what the bar would have been |
|---|---|
| the file is not here | `processed` is about a file that is no longer on disk |
| there is no denominator | Lane GD's full green bar at 0/0, which that lane caught and removed |
| the manifest and the file disagree | **100% over a truncated upload with a third of the catalogue missing** |
| the denominator is 0 | a header-only file is a legitimate export; 0 of 0 is not a proportion |
| the checkpoint was recorded against a different file | `processed` counted rows of the file that was there before it was replaced — two files' arithmetic in one fraction |

The last of those is worth a note. `source_changed` has been in the status
payload since Lane AD and **nothing was using it**: the console divided
`processed` by `rows_total` regardless, so replacing a part-finished entity's
file drew a percentage made of two different files. It suppresses the bar now.

`denominator_source` goes out with every entity — `manifest`, `counted`,
`disputed` or `none` — so a screen can say where the number came from rather
than presenting every number as equally certain. The history page prints it
under each bar: *"100% · from the manifest"*.

### The whole-export bar is all-or-nothing

It is drawn only when **every** present file has a denominator of its own. One
file short is not a smaller total, it is a total that is wrong in the flattering
direction: the bar runs ahead of the work and settles at 100% with a whole
entity still to come. Nine honest bars beat one dishonest one, and the screen
says which it is doing (`why_no_bar`).

The catalogue stage on the live page applies the same rule to the entities that
have a checkpoint.

### An export with no manifest still imports, and still shows progress

Nothing above requires a manifest. With none, `denominator_source` is `counted`,
the per-file bars are exactly what they were, the whole-export bar is absent
(nine files' counts are known, but nothing says they are all the files the
export carries), and the catalogue stage falls back to its old, correct "rows
done is a true number; a bar would not be".

---

## 4. Avoid duplicate import

### First, the thing this is not

**The importer is already idempotent**, and it was proved rather than argued:
671 products, 4,159 orders and 3,712 customers imported twice, second pass
`created 0, updated 0`, byte-identical database (`docs/FV-IMPORT-AT-VOLUME.md`
§4). Checked again here from the screen, in this lane's own run: the second pass
of a 4,042-row export reported `created 0, updated 1` — the one row that had
been corrected — and `unchanged 3,997`.

Nothing in `ImportLedger` protects a row from anything. Delete the whole class
and a double import produces the identical database. **What it protects is the
owner's afternoon**: without it, pressing Import on an export already in is
sixty browser steps, several minutes, and a report that says every row was
unchanged — a correct result, an expensive one, and indistinguishable at a
glance from nothing having worked.

### A file is recognised by its bytes; an export is recognised by its id

That split is the whole design, and it is the brief's own phrasing made
operational:

* **`sha256` decides.** "Have I read this file before" is a question about
  bytes, and bytes are what gets imported. The digest matched is
  `ImportWorkspace`'s own fingerprint — the same one `import_checkpoints.source_fingerprint`
  carries, so the question is asked of one digest rather than two that could
  drift.
* **`export_id` speaks.** It is what the sentence is built from — *"an export of
  https://kbeautybliss.com taken 2026-09-18T09:30:00+04:00"* — and what tells a
  correction from a stranger.

An export with **no manifest at all** keeps the deciding half and loses only the
wording: the bytes are on the disk either way. Proved by a test of its own.

### The three states, and which one blocks

| state | when | blocks? |
|---|---|---|
| **already** | every present file's digest matches a *finished* *live* import | **yes**, with a sentence and an override |
| **partial** | some match and some do not | **no** |
| **changed** | same `export_id`, same entity, different digest | **no** |

**Why `partial` must never block.** It is not an unusual state, it is the normal
one. An import on shared hosting is sixty browser requests and the owner closes
the tab; a delta export carries three files of nine; a run that stopped after
customers is continued the next morning. A guard that refused those would refuse
the resumability this whole console exists to provide. It says which files are
already in and carries on.

**Why `changed` must never block, and this is the one to get right.** "Have I
seen this export id before" is the guard somebody writes in five minutes, and it
is backwards: it refuses the import that carries new information and allows the
one that carries none. The owner found something wrong in WordPress, fixed it,
and exported again — that is the import he most needs to be able to run. It is
reported as its own state with its own sentence so he can see that the shop
noticed.

**And a correction is told apart from an accident.** A digest that does not
match is *either* a correction *or* a file that did not arrive whole, and the
sentence for one is the wrong sentence for the other — calling a truncated
upload "a corrected re-export and it will be imported again" is the shop
reassuring the owner about the thing that is wrong. The manifest's row count is
the only thing that can tell them apart, and it does:

> Orders has CHANGED since it was imported on 18 Sep 2026. **It also does not
> match the row count its own manifest gives it, so this is not a re-export — it
> is a file that did not arrive whole. Upload it again before importing.**

### Four smaller decisions, each with its reason

* **A preview is never blocked.** It writes nothing and rolls itself back.
  Refusing to *look* at an export because it is already imported would refuse
  the one operation that costs nothing, and "I am not sure what is in this file"
  is the usual reason for pressing it. The verdict is still computed and still
  shown.
* **A preview never counts as an import.** A preview row is written with
  `finished = false` and `mode = preview`, and the lookup requires both to be
  the other way. If a preview could satisfy the guard, the shop would refuse the
  real import of an export nobody has imported — the worst failure this feature
  can have, because it refuses the thing the owner came to do. Two locks on that
  door, and both are tested (§7, M25).
* **An unfinished entity never counts as an import.** The same failure one size
  smaller: it would refuse a resumed import.
* **"Forget progress and start over" does not clear the record, so it does not
  clear the refusal.** This is surprising enough to say out loud. Reset deletes
  this screen's *checkpoints*, and its own controller comment is explicit that it
  deletes no row the import wrote — so after a Reset the data is still in the
  shop, and telling the owner that re-importing will change nothing is exactly
  right. He ticks the box. `tests/Feature/AdminImportScreenTest.php` now does the
  same, in two places, with the reason written beside it.

---

## 5. "All record"

### Why a table, and why not the two that exist

Every number the import produces lives in one of three places, and all three are
gone by the time the owner wants them:

| | |
|---|---|
| `ImportReport` | in memory, for the length of one HTTP request |
| `import_runs` | **one row per run key**, overwritten in place by the next run |
| `import_checkpoints` | deleted outright by this screen's own Reset button |

He has no shell and no log access. If the answer is not in a table it does not
exist for him. So `import_history`: **one row per (run, entity)** — not one per
run, because "customers finished and orders refused 14 rows" is the granularity
he reads, and not one per batch, which would be tens of thousands of rows for
one import.

The row is **rewritten on every step of that entity**, so a killed step loses
nothing but the few seconds since the last one. Its counters are read back from
`import_checkpoints`, which committed them inside the same transaction as the
rows they count — the reasoning `ImportDriver`'s own class comment gives about
why the screen does not trust its in-memory tallies.

### What is in it

Which export (`export_id`), taken from where (`source.site_url`) and when
(`generated_at`), the plugin version; per entity: the file, its sha256, the rows
the manifest claimed and the rows found, processed / created / updated /
unchanged / refused, the adjustments and discards **by kind**, the notes, the
count-verification sentence, and whether it finished and when.

**By kind, not just as a total**, because `docs/FV-IMPORT-AT-VOLUME.md` §10 is
the argument for it: *"1,046"* is a number and *"1,046 order amounts carrying
fils on a shop that prints whole dirhams"* is a decision.

### The page

`GET /admin-api/import/history-page` — a standalone document, no build step, no
dependency on the console bundle, for the reason Lane GD gives and one more: a
record of what happened is exactly the screen somebody opens on the day
something is wrong. It does **not** poll; the live view is Lane GD's page and
this one links to it. `history.csv` is the same record as a spreadsheet,
`attachment` + `nosniff` because the note text is lifted out of the owner's own
WooCommerce export.

### And the manifest is a file the owner can remove

The refusal a broken manifest produces ends *"remove the manifest to import the
CSV files without it"*, and that is the only escape hatch there is. The console
draws one card per row of `status()['files']`, with a Remove button that posts
that row's `entity` — so the manifest is a **tenth row in that list**, and
`forget` answers to `'manifest'`. Without it the sentence would name a button on
no screen the owner can reach. **This needs no change to the console**, which is
the reason it was done this way rather than as another anchor in §9.

---

## 6. Everything found wrong in the import path

### Fixed

**F1 — the screen reported its own bookkeeping files to the owner as unimported
data.** `ImportWorkspace` cached each file's row count in
`products.csv.meta.json`, **beside the export**, and
`ImportRunner::reportUnreadFiles()` globs `*.{csv,CSV,tsv,txt,json,xml}` over
the import directory and names everything no importer opens. A live run never
showed it — it passes `only`, and that method exempts a narrowed run — but a
**preview passes no `only`**, so every preview from this screen reported up to
nine of the screen's own sidecars back as *"files in the export folder that no
importer opens"*, in the discard list the owner is asked to approve. The channel
was right and the folder was wrong: sidecars now live in
`storage/app/import/meta/`, and a legacy one beside a CSV is deleted on sight.

**F2 — `manifest.json` was named as a file no importer opens.** It was, and the
volume rehearsal duly named it (§10 of that document). One does now. A list
whose every line is not true is a list the owner learns to skim.

**F3 — the upload endpoint refused more than six files.**
`'files' => ['sometimes','array','max:6']`, while `ImportWorkspace::ENTITIES`
holds **nine** and an export now also carries `manifest.json` — ten. Selecting
the whole export folder, which is the obvious thing to do with a folder of
exports, 422'd the entire request with Laravel's own message about an array
being too large. The cap is derived from the table now, so the next entity does
not reintroduce it.

**F4 — a sliced run over-counted its own discards.** `reportIgnoredColumns()`
emits one entry per entity per `run()` call and the screen calls `run()` once
per browser step, so summing the steps turned "this export has columns nothing
reads" into a count of how many times the browser pressed Step. Merged by kind
now: the row-level kinds add up (each row is read exactly once across a run) and
the per-entity ones restate themselves — the same treatment
`ImportDriver::mergeNotes()` already gives the verification note, for the same
reason. Only visible at all because this lane is the first thing to record those
totals.

**F5 — the volume fixture's `manifest.json` collided with the contract's.**
`tools/woo-volume-fixture/generate.php` wrote a bare flat map of counts under
that name, which the format check would now refuse — so a rehearsal at volume
through the screen would have been blocked. The generator writes the contract's
shape now, with its flat counts under `counts` where the contract puts them,
`unread.*` keys and all, and a deterministic `export_id` derived from the seed.
That is not a compatibility patch: the volume rehearsal now exercises the whole
manifest path against counts computed by the writer rather than read back out of
the reader. `ImportAtVolumeTest` and `ImportCountVerificationTest` read
`['counts']`.

**F6 — a dead condition in this lane's own new code, found by mutation.** The
percent guard tested `mismatch === null` as well as `rows !== null`, and
`denominator()` already returns null rows for a disputed count — so the second
test could never fire. A mutation that removed it stayed green. That is the
shape `Api\ProductController`'s dead `status` filter already cost this
repository once. Removed; `denominator()` is the single place that decides.

**F7 — `source_changed` was computed and used by nobody.** See §3.

### Found and NOT fixed, because they are not this lane's to change

**N1 — the ignored-columns list is a per-slice observation, not a fact about the
file.** `ImportRunner` resets `columnsSeen` / `columnsRead` at the start of every
`run()` call, and a column counts as read only on a row where the importer
reached for it. So a slice of two rows calls a column ignored that the next slice
reads, and the screen always slices. A sliced run and an unsliced run of the same
export genuinely disagree about that count — measured here, and the assertion
that pins everything else deliberately excludes this one kind and says why.
**The proper fix is to carry the two column sets across HTTP requests** in the
checkpoint, which is a change to the importer's own state and not something to
do from here.

**N2 — ▲ Phase 13's count-based verification does not reach a verdict on the
screen.** `EntityReport::verification()` refuses to compare `read − refused`
against the table whenever the run resumed, and its reason is right:
*"it cannot know how many of the rows it did not re-read were refusals"*
(`docs/FV-IMPORT-AT-VOLUME.md` §7, fifth bullet). But **every browser step after
the first resumes**, so every entity that takes more than one step ends on
`counted` — two numbers side by side and no verdict — and at the owner's real
volume that is every entity. It is visible in this lane's own screenshots, seven
times.

The fix is available and is one line of arithmetic: **`import_checkpoints`
already carries `rejected_rows` cumulatively for the run**, alongside
`processed`. `processed − rejected_rows` is exactly the expected row count the
resumed case says it cannot compute. Passing those two into `verify()` would
give the screen the same verdict the command line gets. It changes a verdict that
Lane FV's tests pin by name, so it belongs to whoever owns that work.

**N3 — the console's own bar ignores both new honesty checks.**
`app.blade.php:7929` computes `pct` from `processed / rows_total` with no regard
for `source_changed` or a manifest mismatch. This lane may not edit that file;
the exact change is in §9.

**N4 — ▲ the import tests are not safe to run twice at once in one checkout.**
Found the hard way: two of these tests failed in a run that passed on its own,
twice, and the cause was a second suite running in the same worktree at the same
time. The suite isolates the *database* per process — `tests/bootstrap.php` goes
to some length about it — and `ImportWorkspace::directory()` is
`storage/app/import`, which is **per checkout**, not per process. Two runs then
purge and overwrite each other's uploaded exports, and the failure lands on
whichever test was mid-upload. It looks exactly like flake and it is not.

It is worth knowing because CLAUDE.md's own landmine about intermittent database
errors has the same shape — *check the cheap environmental explanation before
debugging the code* — and because a lane running the SQLite and MySQL halves
concurrently to save time will hit it. `df -h /` was 18 GB free throughout, so it
was not that one. **The fix, if anyone wants it, is a per-process import
directory** (an env var the workspace honours, the way `KBB_TEST_DB` works for
the database); this lane ran the two halves one after the other instead.

**N5 — cosmetic: the catalogue stage's denominator grows as entities start.** It
sums the entities that have a checkpoint, so it reads "1,648 of 3,922" and later
"1,768 of 4,042". The percentage never goes backwards, because the numerator
grows in the same step. Left as it is; a denominator built from the files
instead would be wrong for a delta import, which carries three files of nine.

---

## 7. Every mutation, and its result

Each guard was broken, the suite watched, and the guard restored. **Twenty-eight
mutations. Four did not go red first time. All four are below, all four were
real, and all four are now closed.**

| # | mutation | result |
|---|---|---|
| M1 | manifest: accept any `format` | RED (3 failed) |
| M2 | manifest: read an unreadable `rows` as 0 | RED |
| M3 | manifest: derive `lists()` from "has a row count" | **STILL GREEN** → now RED |
| M4 | denominator: use the manifest's count even when it disagrees with the file | RED |
| M4b | denominator: never detect a disagreement at all | RED |
| M5 | bar: draw a percentage over a denominator of 0 | RED |
| M6 | bar: draw a percentage over a checkpoint about a different file | **STILL GREEN** → now RED |
| M7 | whole-export bar: total whatever denominators exist | RED |
| M7b | whole-export bar: same, for the percentage | RED |
| M8 | duplicate: match the export id instead of the file's bytes | RED |
| M9 | duplicate: count an unfinished entity as imported | RED (2) |
| M10/M25 | duplicate: drop the `mode = live` filter | **STILL GREEN** → now RED (M25 is the same mutation against the test that closed it) |
| M11 | duplicate: block a part-way export too | RED (2) |
| M12 | duplicate: block a preview too | RED |
| M13 | duplicate: ignore the override | RED |
| M14 | manifest: import under a format this shop cannot read | RED |
| M16 | reset: clear the record along with the progress | RED |
| M17 | runner: name `manifest.json` as a file nothing opens | RED |
| M18 | workspace: put the row-count sidecars back beside the export | RED |
| M19 | upload: stop recognising a manifest | RED |
| M20 | upload: accept a manifest this shop cannot read | RED |
| M21 | upload: restore the cap of six files | RED |
| M22 | history: sum the per-entity observations instead of restating them | **STILL GREEN** → now RED |
| M23 | history: replace this run's observations instead of merging them | RED |
| M24 | catalogue stage: total whatever denominators exist | RED |
| M26 | history: let a re-presented entity move the moment it finished | RED |
| M27 | manifest: `lists()` from `rowsFor()` (a second shape of M3) | RED |

### M3 — a manifest entry the plugin could not count

Deriving "is this file in the export" from "does it have a row count" agrees
with the truth on both of the cases the test had — a listed file with `rows: 0`,
and a file that is absent — and is wrong on the third: a file the plugin listed
but could not count. The contract's own rule that unknown keys are ignored
guarantees that third case exists. Closed by a case that carries `bytes` and no
`rows`.

### M6 — the guard that was masked by the guard next to it

The first version of that test replaced a seven-row `categories.csv` with a
two-row one, **under a manifest** — so the row counts disagreed as well, the
mismatch rule suppressed the bar on its own, and `! $sourceChanged` was doing
nothing the test could see. Closed by replacing a file with one of exactly the
same length and no manifest at all, which leaves `source_changed` as the only
thing standing between the owner and a percentage made of two files. This is the
same species as F6 and the reason both are worth reporting: *a guard that is
never the reason a test passes is not a tested guard.*

### M10/M25 — two locks on one door, and only one of them was being turned

Dropping the `mode = live` filter from the duplicate lookup changed nothing,
because a preview row is never written `finished` and the `finished` filter
already excluded it. The redundancy is deliberate — the day somebody makes a
preview mark an entity finished, the guard that refuses the real import of an
export nobody has imported must not be the thing that breaks — so the second
lock is now tested directly, by writing `finished = true` onto the preview rows
and asserting the import still starts.

**And the first version of that test was wrong in a way worth recording**: it
set every row's digest to `products.csv`'s, so only one entity ever matched, the
verdict came back `partial` rather than `already`, and the mutation stayed green
for a reason that had nothing to do with the guard. Each row gets its own
entity's digest now.

### M22 — the sliced-run over-count (F4), which the test that should have caught it excluded

The assertion that a sliced run and an unsliced run report the same observations
deliberately excludes the per-entity kinds, because N1 makes them genuinely
different — and that exclusion is exactly what hid the bug in the merge. Closed
by a test that asserts the restated kind's count is **1** after a run of more
than nine steps, plus a direct test of `mergeKinds()`.

### One harness finding, recorded because it nearly produced a false green

`EntityReport::discards()` is keyed by **kind**, and each entry carries samples;
the file name is the sample's `field` and `before` is its row count. The first
version of the unread-files test read `$d['before']` off the *group*, which
produced an empty list — and an empty list contains neither `refunds.csv` nor
`manifest.json`, so it would have passed against a guard that did nothing. It
now asserts the list is not empty before asserting what is in it.

`expect(...)->not->toContain($needle, $message)` passes vacuously because
`toContain` is variadic; every absence in this file is asserted with
`str_contains(...)->toBeFalse()` or `array_diff`.

---

## 8. Proving it

A real shop, a real multi-batch import through `ImportDriver`, driven over HTTP
as a logged-in owner. `php -S` over the real front controller, its own SQLite
database, `KBB_PUBLIC_PATH` pointing at a web root that is **a different
directory from the application root**, `SESSION_DRIVER=file`, and `vendor/`
hard-linked rather than symlinked. `routes/web.php` was patched with the
one-line anchor from §9 for the duration of the run and reverted afterwards
(`git checkout routes/web.php`), which also proves that anchor applies cleanly.

The export: `tools/woo-volume-fixture/generate.php` at 120 products / 260 orders
/ 180 customers — **4,042 rows across 9 files**, carrying the same deliberate
defects as the volume rehearsal, plus the four files nothing opens and the
contract's `manifest.json`.

### The transcript

```
upload: HTTP 200, accepted 10 refused 0
manifest present: true, usable: true, from: https://kbeautybliss.com
whole export: 4042 rows across 9 files
duplicate verdict: fresh — Nothing here has been imported before —
  an export of https://kbeautybliss.com taken 2026-09-18T09:30:00+04:00.

step  1  categories       59 of 4,042     1%
step  5  products        272 of 4,042     7%
step 11  customers       493 of 4,042    12%
step 16  orders          753 of 4,042    19%
step 27  order-items   1,408 of 4,042    35%
step 35  seo           4,042 of 4,042   100%

  categories      59 of 59     100%  (manifest)  created 56  updated 3 refused 0
  brands          93 of 93     100%  (manifest)  created 85  updated 8 refused 0
  products       120 of 120    100%  (manifest)  created 118 updated 0 refused 2
  coupons         40 of 40     100%  (manifest)  created 40  updated 0 refused 0
  customers      181 of 181    100%  (manifest)  created 180 updated 0 refused 1
  orders         260 of 260    100%  (manifest)  created 260 updated 0 refused 0
  order-items    655 of 655    100%  (manifest)  created 655 updated 0 refused 0
  reviews       2514 of 2514   100%  (manifest)  created 2475 updated 0 refused 39
  seo            120 of 120    100%  (manifest)  created 0  updated 118 refused 2
```

Then the same export again:

```
start: HTTP 409
THIS HAS ALREADY BEEN IMPORTED. All 9 files here are byte-for-byte the ones a
finished import already read — an export of https://kbeautybliss.com taken
2026-09-18T09:30:00+04:00. Importing again is safe and does nothing: every row
is matched on its WooCommerce id, so it would take several minutes and report
every row as unchanged. Upload the newer export instead, or tick the box below
to do it again anyway.
```

Then a corrected re-export — same `export_id`, one product's name fixed:

```
verdict: partial blocking=false
8 of 9 files here have already been imported and 1 has changed since. The
changed one is a corrected re-export and will be read again; the rest will
report as unchanged.
  products  changed  Products has CHANGED since it was imported on 18 Sep 2026.
            This is a corrected re-export and it will be imported again — that
            is the point of it, and nothing here refuses it.
start the corrected re-export: HTTP 200
```

and the record of it, which is the proof that the second pass changed exactly
one row and nothing else:

```
2026-09-18 14:21:45  live  https://kbeautybliss.com  created 0     updated 1    unchanged 3,997
2026-09-18 14:21:32  live  https://kbeautybliss.com  created 3,869 updated 129  unchanged 0
```

Then a truncated upload of `orders.csv`, against the manifest that describes it:

```
orders: percent=NULL source=disputed
orders.csv does not match the export it came with. The manifest says it holds
260 rows and the file on this shop holds 86. That is 174 missing — an upload
that did not finish, or a file opened and re-saved by a spreadsheet. Upload it
again. No progress bar is drawn for it, because a bar over the wrong file
reaches 100% while rows are missing.

overall: percent=NULL — One or more files have no row count that can be
believed, so there is no total for the whole export.
```

### The screenshots

All Chromium (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`) against the
rig above, logged in through the real login form.

| file | what it shows |
|---|---|
| `1-nothing-imported-yet.png` | the record, empty, saying what it is and what it is not |
| `2-export-loaded-and-recognised.png` | the manifest read, the export named, **fresh** |
| `3-mid-import-bar-moving.png` | **1,648 of 4,042 · 41%**, nine per-file bars, seven done and reviews at 10% |
| `4-live-progress-catalogue-bar.png` | Lane GD's page, catalogue stage with a bar per entity where it had none |
| `5-import-complete.png` | every bar at 100%, the whole run recorded |
| `6-already-imported-refused.png` | **ALREADY**, with the sentence and every file's state |
| `7-corrected-re-export-accepted.png` | **PARTIAL**, products `CHANGED`, nothing refused |
| `8-record-of-three-runs.png` | the history, three runs, newest first |
| `9-counts-disagree-no-bar.png` | *counts disagree*, no bar, the red sentence, no whole-export total |

---

## 9. Changes for files this lane may not edit

### `routes/web.php` — one line, and it INSERTS INTO THE MIDDLE

Anchor — **verified: exactly 1 occurrence in `routes/web.php`**:

```php
        require __DIR__.'/import-admin.php';
```

Replacement:

```php
        require __DIR__.'/import-admin.php';

        // Store → Import → "What has been imported" (Lane GF). The read side of
        // the same screen: the record of which export this shop's data came
        // from, when it was taken and what each run did. Same group, because it
        // names the owner's own site, the digests of his export files and the
        // note text lifted out of his catalogue.
        require __DIR__.'/import-history-admin.php';
```

It goes **inside the existing `admin-api` group** that already carries
`auth:admin` and `NoStoreAdminApi` — the same group `import-admin.php` is in,
which is what the anchor guarantees. This patch was applied and reverted during
§8's run, so it is known to apply.

**And delete the "UNMOUNTED AS SHIPPED" paragraph from the head of
`routes/import-history-admin.php` when you do.** A route file that still claims
to be unmounted after it has been mounted is a lie the next lane reads and
believes.

`AdminCapabilities::RULES` needs **nothing**. `['*', 'admin-api/import/**',
'data.import']` already covers all three paths and `**` matches everything
beneath the prefix — which is exactly why they sit under `/import/` rather than
under a prefix of this lane's own, which would have fallen through to the closed
owner-only default. **A new RULES entry beneath that wildcard would be dead
text**, shadowed by it. Checked rather than assumed, and pinned by a test that
also asserts no such rule was added.

### `resources/views/admin/app.blade.php` — two changes, both optional

**(a) a link to the record.** Anchor — **verified: exactly 1 occurrence**:

```javascript
    +gdLiveProgressCard()
```

Replacement (**inserts a line**; it is one entry in a chain of string
concatenations, so the trailing `+` matters):

```javascript
    +gdLiveProgressCard()
    +gfHistoryCard()
```

and, beside the other card functions — anchor, **exactly 1 occurrence**:

```javascript
function gdLiveProgressCard(){
```

Replacement (**inserts a whole function above it**):

```javascript
function gfHistoryCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">What has been imported</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Every import this shop has run: which export it came from, when that export was taken off the old '
    +'site, and what each run created, updated, left alone or refused. It is kept even when you press '
    +'&ldquo;Forget progress and start over&rdquo;.</p>'
    +'<a href="'+impBase()+'/import/history-page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the record</button></a></div>';
}

function gdLiveProgressCard(){
```

**(b) the console's own bar, which ignores both honesty checks (N2).** Anchor —
**verified: exactly 1 occurrence**:

```javascript
    const pct=e.rows_total?Math.min(100,Math.round(100*e.processed/e.rows_total)):0;
```

Replacement (**replaces in place**):

```javascript
    /* THE SERVER DECIDES WHETHER THERE IS A BAR. `percent` is null when the
       number would be a lie — no denominator, a manifest whose row count
       disagrees with the file on disk, or a checkpoint recorded against a file
       that has since been replaced. Dividing processed by rows_total here drew
       a confident bar in all three. Lane GF; docs/GF-IMPORT-REFINEMENT.md §3. */
    const pct=e.percent==null?null:e.percent;
```

and, immediately below it — anchor, **exactly 1 occurrence**:

```javascript
      +'<div class="impbar"><i style="width:'+pct+'%"></i></div></div>';
```

Replacement (**replaces in place**):

```javascript
      +(pct==null?'<div style="font-size:11.5px;color:var(--ink-soft)">'
          +(e.rows_mismatch?impEsc(e.rows_mismatch.sentence)
            :(e.source_changed?'This file has changed since the run stopped, so there is no honest '
              +'progress bar for it — start it again from row one.'
              :'No row count for this file, so there is no progress bar.'))+'</div>'
        :'<div class="impbar"><i style="width:'+pct+'%"></i></div>')+'</div>';
```

The status payload also carries `overall`, `manifest` and `duplicate`, which the
console could draw a whole-export bar and the duplicate warning from. Left to
whoever owns that file; the record page draws all three today.

### `KBB-Master-Plan.md` — a new Phase 13 bullet, INSERTED INTO THE MIDDLE

It goes directly after Lane GD's live-progress bullet, because it is the answer
to the sentence that bullet ends on. Anchor — **verified: exactly 1 occurrence
in `KBB-Master-Plan.md`**, and it is the last line of that bullet:

```markdown
  prevent. A stage with no denominator now gets no bar
```

Replacement — the anchor line, then the new bullet. There are further bullets
after it, so this **inserts into the middle** and does not append to the file:

```markdown
  prevent. A stage with no denominator now gets no bar
- [x] **The import refined: an honest bar, a duplicate guard, and a record that
  outlives the run** — `manifest.json`'s `rows` gives the catalogue import a real
  denominator, per file and for the whole export, and **five separate conditions
  suppress a bar rather than draw a wrong one** — including a manifest whose
  count disagrees with the file, which is the only way a truncated upload is
  ever noticed. An export already imported is **refused with a sentence** before
  the owner waits several minutes, with a deliberate override; a part-way import
  and a **corrected re-export are never refused**. `import_history` records which
  export the shop's data came from, taken from where and when, and what every
  run did — it survives the next run and it survives Reset, and there is a page
  at Store → Import. 47 tests, 28 mutations, driven end to end in a browser at
  4,042 rows — see `docs/GF-IMPORT-REFINEMENT.md`
```

---

## 10. What only the owner can settle

1. **Does the duplicate refusal want to be a refusal at all, or a warning he can
   press through in one click?** It is a refusal today: a 409 with a sentence and
   a tick box. The argument for the refusal is that it is the only thing standing
   between him and five wasted minutes; the argument against is that it is one
   more click on the day he genuinely wants the second pass, which is also the
   cheapest proof there is that the import worked. Either is defensible, and it
   is two lines to change.

2. **N2 — the count-based verification that never reaches a verdict on the
   screen.** Phase 13 asks for count-based verification after each bucket and it
   is there, but from the browser it says "the two numbers are reported side by
   side rather than compared" on every entity that takes more than one step. The
   fix is available and is arithmetic the checkpoint already has. Whoever owns
   Lane FV's tests should decide whether to take it.

3. **N4 — should the import tests get a per-process workspace?** It is a
   one-line change to `ImportWorkspace::directory()` plus an env var, and it
   removes a class of failure that reads as flake. It is not this lane's file to
   settle unilaterally because every import test in the repo runs through it.

4. **N1 — the ignored-columns list.** The owner is asked to approve "columns in
   this export that no field of this importer reads", and from the screen that
   list is a per-slice observation. It is not wrong about the columns it names;
   it is incomplete in a way that varies with the step size. Worth knowing before
   he approves it.

5. **Everything `docs/FV-IMPORT-AT-VOLUME.md` §13 still lists** is unchanged by
   this lane: the discard list, the empty `refunds` and `product_variants`
   tables, and the seven fixed-amount coupons carrying fils.

---

## 11. Reproducing this

```bash
# the export, with the contract's manifest
php tools/woo-volume-fixture/generate.php /tmp/gf-export \
    --products=120 --orders=260 --customers=180
cat /tmp/gf-export/manifest.json

vendor/bin/pest tests/Feature/GfImportRefinementTest.php
vendor/bin/pest tests/Feature/AdminImportScreenTest.php
vendor/bin/pest tests/Feature/ImportAtVolumeTest.php
vendor/bin/pest tests/Feature/ImportCountVerificationTest.php

vendor/bin/pest
KBB_TEST_DB=kbb_gf vendor/bin/pest -c phpunit-mysql.xml
find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l
```

**Tests run on file-based SQLite.** Not `:memory:` — CLAUDE.md says why, and it
is not negotiable here either.

### What shipped

| file | what it is |
|---|---|
| `app/Services/ImportConsole/ImportManifest.php` | the reader for `manifest.json` |
| `app/Services/ImportConsole/ImportLedger.php` | the duplicate verdict and the record |
| `app/Http/Controllers/Admin/ImportHistoryApiController.php` | three read endpoints |
| `routes/import-history-admin.php` | where they mount — **one line for the integrator** |
| `resources/views/admin/import-history.blade.php` | the record page |
| `database/migrations/2026_11_23_000000_create_import_history.php` | the table, plus `run_uid` and `manifest` on `import_runs` |
| `database/migrations/2026_11_23_000001_clear_caches_import_refinement.php` | the `clear_caches_*` that ships with any new route |
| `tests/Feature/GfImportRefinementTest.php` | 47 tests, 1,447 assertions |
| `tests/Support/ImportHistoryRoutes.php` | mounts the route file the way the integrator is told to |
| `docs/gf-import-shots/` | nine screenshots |

Edited: `ImportDriver`, `ImportWorkspace`, `ImportApiController`,
`MigrationProgress`, `ImportRunner` (one line), `media-progress.blade.php`,
`tools/woo-volume-fixture/generate.php`, and three existing test files —
each change explained above and commented where it sits.
