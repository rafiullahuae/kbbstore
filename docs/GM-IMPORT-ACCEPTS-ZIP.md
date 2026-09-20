# Store → Import accepts a group's zip · Lane GM

The owner's words, to the WordPress side:

> *"allow to download each group seperate files. so will have no any heavy
> file."*

Lane GK split the export into eight named groups. Lane GL turns each group into
its own downloadable zip, carrying its own `manifest.json`, all of them sharing
one `export_id`. **This lane is the other end of that pipe**: Store → Import
takes the zips, so the owner never unzips anything — which was the entire point,
because before this `ImportApiController::upload()` validated `'files.*' =>
['file']` and `ImportWorkspace::refuseNonText()` answered a zip with *"That is a
spreadsheet (.xlsx) or a zip, not a CSV."*

The evidence is `tests/Feature/GmImportAcceptsZipTest.php` (54 tests, 273
assertions), the browser run is `tests/browser/gm-import-accepts-zip.mjs` with
its shots in `docs/gm-zip-shots/`, and §7 is the three changes to files this
lane may not edit.

---

## 1. The headline

| | |
|---|---|
| new routes | **none** — §6 |
| new capability rules | **none**; `admin-api/import/**` → `data.import` already covers it, checked through the resolver rather than assumed |
| new migrations | **none**, because no route was added |
| new Composer packages | **none**; PHP's `ZipArchive`, as required |
| the invariant | **a zip behaves exactly as if its files had been uploaded loose** — every member goes through `ImportWorkspace::accept()`, so the CSV parse, the id-column check, the manifest reader and every refusal sentence are the ones the loose path already had |
| loose uploads | unchanged, and asserted rather than assumed |
| security guards | 8, each with a test that fails when **only that guard** is removed |
| several group zips | manifests **merge**; the denominator **adds up**; the duplicate guard reports `partial` and does not block |
| `assumed_already_imported` | carried into the status payload as a **notice**, never a refusal — §5 |
| mutations | **35 written, 7 survived a first pass, 35 red now; 2 rules were wrong, 2 pieces of code were dead and were deleted** — §8 |
| the whole suite | **4,673 green on SQLite, 4,679 green on MySQL**, zero failures, `php -l` clean |

**Four of this lane's own rules were wrong.** Mutation testing found two of them
(§8.1, §8.2); **Lane GF's existing suite found the other two** (§4.4, §4.5), and
one of those would have told the owner that a corrected re-export was a
truncated upload. Two further pieces of code were dead text and were deleted
rather than left in with a reassuring comment (§8.3, §8.4).

---

## 2. What was assumed about GL's zip, and how the tolerance was built

`docs/GL-GROUP-DOWNLOADS.md` **did not exist when this was written** — Lane GL
was running at the same time. So this side was built against
`docs/WP-EXPORT-CONTRACT.md`, which the integrator owns and which neither lane
may change, and made tolerant of the choices the contract leaves open.

**What was assumed, and what the integrator should check against GL's doc:**

| assumption | tolerance built for it | what happens if GL differs |
|---|---|---|
| the zip holds the group's CSVs and its `manifest.json` | both are read; anything else is skipped, not fatal | nothing |
| files at the zip's **root** | also accepted **inside exactly one wrapping directory**, whatever it is named | nothing — `it reads a zip that wraps its files in one directory` covers it |
| the CSV names are the contract's, lowercase | the same fixed table the loose upload uses | a differently-named file is refused **by name**, with the existing *"Which export is this?"* sentence naming every accepted filename |
| one `export_id` shared by every group's zip | manifests merge on matching `export_id`, replace otherwise | a per-zip id means each zip replaces the last — the shop would still import correctly, but every earlier group would lose its manifest denominator (§4). **This is the one assumption worth confirming with GL.** |
| `groups` as `docs/GK-EXPORT-GROUPS.md` §5 writes it | every field defaulted; a manifest with no `groups` reads as an export that ticked nothing | nothing |

**Two shapes are deliberately refused rather than guessed at:**

- **Two folders deep** (`export/catalogue/products.csv`). One wrapping directory
  is a shape this side could plausibly have guessed wrong about; two is a shape
  nobody agreed to, and quietly flattening it would mean importing a file from a
  layout neither end designed. The sentence names what was found.
- **Export files in two different places at once** (`catalogue/products.csv` and
  `sales/orders.csv` in one zip). That is two exports in one archive, and
  choosing which one to import is not a decision this code may make silently.
  If GL ever ships an "everything" zip built that way, this refuses it and the
  sentence says to upload the groups one at a time — the integrator should read
  that as a thing to reconcile, not as a bug to work around.

**One gap that is nobody's bug and belongs in the integrator's notes.** GK's
`addresses` group is `permalinks.csv` and `media.csv`. Neither is in
`ImportWorkspace::ENTITIES` — the contract lists both as **gap** files whose
importers come later, and they are consumed by `kbb:import-redirects` and
`MediaSideloader` rather than by `kbb:import`. So an *Addresses and pictures*
zip unpacks, and both of its files are then refused one by one with the existing
*"Which export is this?"* sentence. **That is exactly what a loose upload of the
same two files does today**, so the invariant holds and nothing regressed — but
the owner who downloads that group will get two refusals and no import. It is
not this lane's to fix (there is no importer to route them to) and it is not
GL's either. It is pinned by
`it refuses the addresses group file by file, exactly as a loose upload would`
so that it cannot change by accident, and it wants saying out loud before the
owner finds it — §10.2.

---

## 3. The security model

The upload is the attack surface, and a zip is worse than a file: a loose upload
carries **one** name that `ImportWorkspace` already discards, while a zip
carries as many names as it likes, plus a declared size per member that it also
chooses, plus a unix mode. `App\Services\ImportConsole\ImportArchive` answers one
question — *which byte streams may leave this archive* — and hands them to
`accept()`.

**Nothing is extracted into the workspace.** Each upload gets a scratch
directory of its own under `storage/app/import/incoming`, mode 0700, with a
random name, removed in a `finally`. It is a **sibling** of the import directory
and never inside it, because `ImportRunner::reportUnreadFiles()` globs the
import directory and names everything no importer opens in the discard list the
owner is asked to approve — the same trap that moved the row-count sidecars out
(`ImportWorkspace::metaDirectory()`'s comment). A half-unpacked zip showing up
in that list would have been this lane repeating a mistake already paid for.

| # | guard | how it was tested | mutation |
|---|---|---|---|
| 1 | the archive opens, with `ZipArchive::CHECKCONS` | a zip truncated at half its length — the central directory is at the END of a zip, so a download that stopped has no index at all | M15 |
| 2 | **zip slip** — `..`, `.`, an empty segment, a backslash, a drive letter, a NUL | five archives, **two of them for traversal alone** — see below | M1, M2, M3 |
| 3 | at most **one** wrapping directory, shared by every member | `export/catalogue/products.csv`; and two groups' files under two folders | M4, M14 |
| 4 | **allowlist**: `manifest.json` or `<name>.csv` and nothing else | a zip holding `shell.php`, `.htaccess` and `index.html` alongside one real CSV | M10, M11 |
| 5 | one destination claimed once | `products.csv` and `export/products.csv` — see below | M13 |
| 6 | **zip bomb**, counted from bytes actually read | a deflated member declaring 512 bytes and holding 80MB | M8 |
| 6b | the declared size, checked first because it is free | a member declaring 900MB; and six members of 40MB each | M6, M7 |
| 7 | entry count, checked **before** the loop | `MAX_ENTRIES + 1` members | M9 |
| 8 | **symlink entries**, by unix mode in the external attributes | a member named `products.csv`, mode `0120777`, content `/home/user/kbbstore/.env` | M5 |

### 3.1 Zip slip is tested twice, and the second test is the one that matters

`../../../.env` is refused by **two** guards at once — the traversal check and
the allowlist — so a test built only on it would stay green with the traversal
check deleted. That is the shape this repository has already paid for twice:
`Api\ProductController`'s dead status filter, and Lane GF's own second
`mismatch === null` in `ImportDriver::status()`.

So the second fixture is **`../../../../products.csv`**. Its basename is
`products.csv`, which the allowlist is perfectly happy with. Only the traversal
check can refuse it, and `it refuses a traversal whose file name the allowlist
would allow` is the test that goes red when that check alone is removed — which
is what M1 confirms (3 tests red, not 1).

The same reasoning produced the **backslash** fixture. `..\..\products.csv` is a
traversal on the host that wrote the archive and contains no `/` at all, so a
check that split on `/` would wave it straight through.

### 3.2 A symlink escapes without a `..` anywhere in it

A zip entry can carry a unix mode saying "this is a symlink", and its content is
then the link target. The fixture's name is `products.csv` — allowed,
unambiguous, entirely ordinary — and the only thing wrong with it is the mode in
its external attributes.

PHP's `extractTo()` happens to write such an entry as an ordinary file, and this
class deliberately does **not** rely on that: the property being relied on would
be an implementation detail of a C library rather than a decision made here.

**This paragraph first claimed that deleting the check would be harmless because
`refuseNonText()` catches the entry anyway. That was written from reasoning
rather than measured, and it is wrong.** Run with the guard removed, the upload
returns **200 and the file lands**: the content `/home/user/kbbstore/.env` has no
NUL, no `PK`, no `%PDF` and no `<`, so `refuseNonText()` passes it; `CsvRowSource`
then reads it as a header with no data rows, which `accept()` explicitly permits
(*"a delta export of a week with no new customers looks like this"*); and the
filename says products, so it is filed as `products.csv`.

So guard 8 is not a second opinion — **it is the only thing standing between a
symlink entry and the workspace**, and the honest version of this section is
stronger than the one that was written first. The test asserts the sentence *and*
that nothing was written; M5 is red on both counts. Recorded at length because a
claim about a guard being redundant is exactly the kind that gets a guard
deleted.

### 3.3 The bomb cap is counted from bytes read, not from the header

`$stat['size']` is a number the archive's author wrote. The fixture declares
**512 bytes** and holds **80MB**, deflated so the archive itself is a few hundred
kilobytes.

Two halves, and both are real:

- the **declared** size is checked first, before a byte is written, because the
  honest bomb — a tool with no reason to lie — is the common one and refusing it
  should cost nothing;
- the **streamed** size is checked in 64KB chunks with a running total and the
  entry is abandoned **mid-read**, because a cap applied to the finished file is
  not a cap, it is a report on the damage.

Getting the fixture right mattered as much as the guard: a **stored** (undeflated)
bomb is an 80MB upload, which this shop refuses at the door for being 80MB, and
the test would have passed green with the expansion guard deleted. The deflate
flag in `tests/Support/ZipBuilder.php` carries that note.

### 3.4 Nothing that is not an export file is written anywhere

An allowlist of two names, not a blocklist of dangerous ones: the blocklist
version is wrong the day somebody invents an extension. `it never writes a php
file anywhere, not even to the scratch directory` uploads a zip holding
`shell.php`, `.htaccess`, `index.html` and one real CSV, and asserts that
**everything under `storage/app/import`** is the one CSV and its row-count
sidecar. It checks the whole tree rather than the `woo/` folder because the
scratch directory is where a `.php` *would* land if the allowlist ran after
extraction instead of before it.

M11 turns the allowlist into a `.php` blocklist — the shape somebody will
propose — and it goes red on two tests.

A **nested archive** is refused the same way: `inner.zip` is not on the
allowlist, so it is never written and never opened. And a member named
`products.csv` whose *contents* are a zip reaches `refuseNonText()` and gets the
spreadsheet sentence, exactly as a loose upload of those bytes would. One more
turn of the recursion is never taken.

### 3.5 Two findings about the guards that are worth the integrator's time

**A name that is merely not an export file must not be fatal.** The first
version of `refuseUnsafeName()` required every path segment to match
`^[A-Za-z0-9][A-Za-z0-9._-]*$` and threw otherwise. That refused, outright: any
zip made on a Mac (`__MACOSX/`, `.DS_Store`), any zip carrying a `.htaccess`,
and — worst — every spreadsheet, with a sentence about file names instead of the
one naming it as a spreadsheet that `ImportWorkspace`'s rule 4 exists to give.
**Fatal is for a name that says WHERE; the allowlist is for a name that says
WHAT**, and those are two different questions. The check moved, and
`it accepts a zip made on a Mac, junk folders and all` is now the test that
stops it moving back — the owner right-clicking a folder and choosing *Compress*
is a thing he will do, and it was refused.

**Identical member names never reach this lane's duplicate guard.** libzip
refuses the archive at open under `CHECKCONS`. That is a fine outcome and
`it refuses an archive holding two members with the identical name` pins it — but
writing the duplicate test that way would have left this lane's own guard as
dead text. The collision that IS reachable is `products.csv` and
`export/products.csv`: two names libzip is happy with, one flat destination. That
is what the test uses, and M13 is red because of it.

---

## 4. Several group zips, and the thing a replace would quietly cost

Catalogue on Monday and Orders on Tuesday is the **normal** use of this feature,
so three things have to hold across two uploads.

### 4.1 The manifests merge

Letting the second manifest replace the first is the obvious thing and it is
wrong, in a way quiet enough to survive a demo. The contract:

> A file with no rows is still listed, with `"rows": 0`. **Absent from `files`
> means the plugin did not write it at all**, which is a different statement and
> the importer must be able to tell the two apart.

After a replace, `products.csv` is sitting on the disk and is **absent from the
manifest beside it** — so the shop now believes the export does not carry
products. Nothing 500s. The bar still draws, because `ImportDriver::denominator()`
falls back to the count `ImportWorkspace` took off the file itself. What is lost
is the only thing that count cannot do: **notice that the file on this disk is
half the file that was sent.** `ImportManifest`'s own class comment says the
manifest's largest contribution is "a denominator that can be WRONG, and
therefore one that can be checked", and a replace silently gives that up for
every group but the last one uploaded.

`ImportManifest::merge()` therefore folds them:

| field | rule | why |
|---|---|---|
| `export_id` | equal by precondition | a merge only happens when they match |
| `source`, `generated_at`, unknown keys | **first wins**; the incoming manifest's unknown keys are added, never overwritten | the first zip is the one that began the export; and the contract says a newer plugin's fields must not stop an import, so dropping them quietly is a smaller version of stopping it |
| `files`, `counts` | union, and **the INCOMING entry wins** where they overlap | §4.4 — the opposite of the row above, and getting it the other way round calls a corrected re-export a truncated upload |
| `groups.selected`, `groups.files` | **union** | ditto |
| `groups.skipped` | **union, minus everything selected** | §8.1 — this was an intersection and the intersection was both redundant and wrong |
| `groups.assumed_already_imported` | union, one claim per `(group, needs)`, the louder severity winning a tie | GK draws exactly one edge red and the point is that red means something; a duplicate must not be able to quieten it |
| `merged_from` | appended, **last 20 kept** | a merged file that looked like an original would be one the owner could not reconcile against what he downloaded; the bound is §4.6 |

**A manifest of a different export replaces**, as it always did. **So does one
with no `export_id` at all** — two anonymous manifests are two unknowns, and
treating two unknowns as one export would join a January download to a September
one because neither said which it was. `sameExport()` requires the left id to be
non-null for exactly that reason, and M18 is the test that keeps it.

**The merge is on the loose-upload path too, not only the zip one**, and that is
deliberate: the invariant is that a zip behaves exactly as if its files had been
uploaded by hand, and two manifests dragged into the box by hand are the same two
manifests. No existing test pinned "the second manifest replaces the first".

### 4.2 The progress denominator: it adds up, and here is why that was the choice

The brief asked which of two outcomes to aim for — a denominator that adds up
correctly, or no bar at all. **It adds up**, and the reason is that there was
never a hard case:

- `ImportDriver::status()` computes the whole-export bar over **present**
  entities only. Three files on disk out of seventeen is not a missing
  denominator, it is a denominator over three files.
- Each present file keeps the count from the manifest that described it, because
  the merge preserved it. The assertion in
  `it keeps every file its own row count across the merge` is
  `denominator_source === 'manifest'` for **every** present entity, not the
  number — `counted` would still produce a bar, and would still be right about
  the file on this disk. What it cannot do is notice a truncated upload.
- The whole-export bar is all-or-nothing and stays that way: GF made it draw
  only when **every** present file has a denominator that can be believed. One
  file short is not a smaller total, it is a total wrong in the flattering
  direction.

So "a denominator that would be wrong" does not arise from partial exports. It
arises from a **truncated file**, and `it still refuses a bar when one group zip
carried a truncated file` proves the merge did not disarm that: a sales zip whose
manifest overstates `orders.csv` by 500 rows leaves orders at
`denominator_source: disputed`, `percent: null`, the whole-export total `null` —
and categories, from the earlier zip, still at `manifest`. Nine honest bars beat
one dishonest one, per file, across zips.

Measured in the browser, three catalogue files then two sales files:

```
after catalogue.zip — files in manifest: [ categories.csv, brands.csv, products.csv ]
  whole-export total: 20 over 3 files
after orders.zip    — files in manifest: [ categories.csv, brands.csv, products.csv,
                                           orders.csv, order_items.csv ]
  whole-export total: 37 over 5 files
  denominators: categories=7/manifest brands=3/manifest products=10/manifest
                orders=10/manifest order-items=7/manifest
```

### 4.3 The duplicate guard is not fooled, in either direction

GF's guard matches files by `sha256` and names the export by `export_id`, and
deliberately never blocks a `partial` or a `changed`. Two tests hold that line
from both sides, each importing for real first:

- **Catalogue imported, then the Orders zip uploaded** → `partial`, `blocking:
  false`, `imported: 2`, `new: 1`. GF's reasoning applies directly: an import on
  shared hosting is sixty browser requests and a delta export carries three files
  of nine, so a guard that refused this would refuse the feature this lane is
  building.
- **The same Catalogue zip uploaded twice** → `already`, `blocking: true`. Still
  recognised, which is the half that has to keep working.

### 4.4 A `files` entry is the one thing the NEWER manifest wins

Everything else in a merge is first-wins. A `files` entry is not, because it
describes **one file's bytes**, and the bytes on the disk are the ones that
arrived with the manifest describing them.

This was written first-wins and **Lane GF's existing suite caught it within a
minute** — `it draws NO bar when the manifest and the file disagree about how
many rows it holds` went red. What it caught is the owner's most important
import. He finds something wrong in WordPress, fixes it, and re-exports **that
group** under the same `export_id`. Under first-wins the shop keeps the old row
count, compares it to the corrected file, and reports the correction as a
truncated upload — *"it is a file that did not arrive whole. Upload it again"* —
about the one file that is finally right. GF is explicit that a corrected
re-export "is the import he most needs to be able to run".

`it lets a corrected re-export of one group update that file row count` is this
lane's own test for it; M31 and M32 are the mutations.

### 4.5 Pressing Remove is the one omission that has to be a statement

A merge treats a manifest that does not mention a file as **silent** rather than
as a deletion. That is the entire reason it exists: the Catalogue zip's manifest
omits `orders.csv`, and that omission must not delete the Sales zip's entry.

Exactly one action means the opposite, and before this lane it said so for free —
the owner removed a file, re-uploaded a manifest built from what was left, and
the replace took the entry with it. Under a merge the stale entry survives, and
that is not cosmetic: `ImportDriver::denominator()` gives an **absent** file the
manifest's row count (there is no counted number for it to disagree with), and
`MigrationProgress` then sums a total over work that is not there — the bar
running ahead of the import and settling at 100% with a whole file still to
come, which is what Lane GD removed once already.

Found by **GF's** `it gives the catalogue stage no total when only some of its
entities have one`. `ImportWorkspace::forget()` now drops that one file from the
manifest — rewritten, not deleted, because the export id, the source and every
other file's counts are still true and the duplicate guard and the history are
built out of them. M33 and M34 are the mutations; the second of those is why the
test asserts the manifest is still **there**.

### 4.6 The merge's own bookkeeping is bounded, because it rewrites the file

A merge **rewrites** `manifest.json`, so `merged_from` grows by one on every
upload of a group belonging to this export. `ImportManifest::read()` refuses a
manifest over `MAX_BYTES` with *"this is not a manifest — it is a list of counts
and it should be a few kilobytes. Remove it."*

Unbounded, an owner who re-uploaded one group often enough would eventually
brick his own manifest **with this shop's own record-keeping** — the worst shape
a record-keeping field can have, because the thing that fails is not the thing
he did. It keeps the last `MAX_MERGED_FROM` = 20, which is every one of GK's
eight groups with room for corrections, and the oldest go first because the
newest are the ones still being reconciled against a download.

The test uploads the same group zip twenty-five times and asserts what actually
matters — that the manifest is still **readable**, still names its export, and
still gives categories a manifest denominator — rather than only that an array
is short.

---

## 5. `groups.assumed_already_imported` — printed, and why

GK's account of it:

> `assumed_already_imported` is the only thing in the export that a file list
> cannot carry. It is a claim about the **other** shop, the plugin cannot check
> it and does not pretend to.

**Decision: yes, it belongs on the import screen.** This lane put it in the
status payload — `manifest.groups.assumed_already_imported`, with `group`,
`needs`, `severity` and the plugin's own wording — and tests that it arrives
there (M21 is red on five tests). **Drawing it is §7.3**, an anchor and a
replacement, because `resources/views/admin/app.blade.php` is not this lane's to
edit. Until the integrator applies that block the claim is carried and not
shown, and this section is an argument rather than a description.

**The argument for.** The person pressing Import is standing in front of the only
shop that can answer the claim. GK's one `loses` edge is `sales` → `customers`:
`OrderImporter` links by billing email, synthesises a guest with a NULL
`wp_user_id`, and the genuine WordPress user is then refused as an email
collision — **14 of 80 customers lost** on the measured rehearsal
(`docs/FV-IMPORT-AT-VOLUME.md` §6). GK is precise that the damage *is* reported
and its **cause** is not: the refusals appear in the customer import, which may
be weeks later and is a different button press, and nothing in that report says
"because an export taken on the 9th carried orders and not customers". Printing
the claim above the run is the last moment that connection costs nothing to make.

**The argument against, and why it loses.** It is a claim about a shop the
exporter could not see, made possibly weeks ago, and it may simply be true —
showing it every time risks becoming one more banner nobody reads. But it appears
only when the operator actually ticked the box, which is rare by construction,
and the alternative is that the one unverified statement in the whole export is
the one thing the export does not say out loud.

**It is a NOTICE AND NOT A REFUSAL, and that is the load-bearing half of the
decision.** GK refuses to let the *export* start with a warning unanswered, which
is the right place for a refusal — the operator is on the screen that knows the
dependency graph. Refusing again here would refuse the partial import this whole
console exists to make possible, and would be this shop overruling a decision it
has strictly less information about than the person who made it. The test asserts
both halves: the claim reaches the payload, `duplicate.blocking` is false, and
`start` still returns 200.

Two smaller decisions inside it:

- **An unrecognised `severity` shows as the quiet one.** GK draws exactly one
  edge red and says why: a banner that shows up eight times is a banner nobody
  reads. Guessing an unknown value upward would let a newer plugin's ordinary
  export turn this shop's one red warning into noise. M22 pins it.
- **A claim that has since been satisfied is kept.** If the Catalogue zip arrives
  after the Orders zip that claimed catalogue was already in the shop, the claim
  is still a record of what the operator clicked, and that does not stop having
  been true.

---

## 6. No new route, no new rule, no migration — and it was checked

CLAUDE.md: *"A new admin route needs a rule in `AdminCapabilities::RULES`
**unless** an existing wildcard already covers it ... Check rather than assume.
Any new route needs a `clear_caches_*` migration."*

**A zip arrives at `POST /admin-api/import/upload`, the endpoint that already
existed**, and what a zip *is* is decided from the bytes — `PK\x03\x04` or
`PK\x05\x06` — never from the extension and never from the Content-Type the
browser volunteered. M26 replaces that with an extension check and goes red; the
test uploads a real zip named `categories.csv` and served as `text/csv`.

That was a design choice and not an accident, so there is a test asserting it:
`it adds no new route, so it needs no capability rule and no cache migration`
pins the exact eight routes `routes/import-admin.php` registers. A new endpoint
would have needed a rule, a route line, and a `clear_caches_*` migration to be
reachable at all on a host with a compiled route cache — and, more to the point,
it would have needed the owner to know which box a zip goes in, and a zip in the
wrong box is the unzip-by-hand this feature exists to remove.

The existing rule was **checked, not assumed**, and checked through the resolver
rather than by reading the table, so a rule shadowed by an earlier wildcard would
show up as the capability the earlier one grants:

```php
expect(AdminCapabilities::forPath('POST', 'admin-api/import/upload'))->toBe('data.import');
```

`routes/import-admin.php` says it is WIRED and it is (`routes/web.php:428`); this
lane added nothing to it, so nothing in its header needed correcting.

---

## 7. Changes for files this lane may not edit

### 7.1 `resources/views/admin/app.blade.php` — ONE attribute, and it INSERTS INTO THE MIDDLE

The file picker filters to CSVs, so the owner has to switch it to *All Files* to
see a zip. Drag-and-drop already works — `accept` is only a hint to the picker —
and so does choosing *All Files*, so **this is a polish change and not a
blocker**: the zip path works today without it.

**Anchor** — occurs exactly **once** (`grep -c`, verified):

```
    +'<input type="file" id="impFileInput" accept=".csv,text/csv" multiple hidden></div>'
```

**Replacement:**

```
    +'<input type="file" id="impFileInput" accept=".csv,text/csv,.zip,application/zip" multiple hidden></div>'
```

### 7.2 `resources/views/admin/app.blade.php` — the drop-box wording, also ONE occurrence, also middle

Optional, and it is the sentence the owner reads before he does the thing.

**Anchor** — occurs exactly **once**:

```
    +'<div class="impdrop" id="impDrop"><b>Drop your CSV files here, or click to choose</b>'
```

**Replacement:**

```
    +'<div class="impdrop" id="impDrop"><b>Drop your CSV files or a group\'s .zip here, or click to choose</b>'
```

### 7.3 `resources/views/admin/app.blade.php` — drawing the operator's claim, INSERTS INTO THE MIDDLE

§5 is the argument for this one. The data is already in the status payload and
tested; this is the block that puts it in front of the person pressing Import.

**Anchor** — the line occurs exactly **once** (`grep -c "^    +impBanner(run)$"`
= 1), inside the `$('#content').innerHTML=` assignment:

```
    +impBanner(run)
```

**Replacement** — one call added after it, so the notice sits between the run
banner and the files card:

```
    +impBanner(run)
    +impAssumedCard(s)
```

**And one new function**, appended beside the other card builders. It renders
nothing at all when there is no claim, which is the usual case:

```js
/*
 * What the operator SAID was already in this shop, which the export plugin
 * could not check. docs/GK-EXPORT-GROUPS.md §5: the claim travels in the
 * manifest so it does not have to live in his memory, and this is the one
 * moment somebody is looking at the shop the claim is about.
 *
 * A notice, never a refusal. The export screen already refused to start with
 * an unanswered warning, which is where a refusal belongs; refusing here
 * would refuse the partial import this console exists to make possible.
 */
function impAssumedCard(s){
  const claims=((s.manifest&&s.manifest.groups&&s.manifest.groups.assumed_already_imported)||[]);
  if(!claims.length) return '';

  return '<div class="card pad" style="margin-bottom:16px;border-left:3px solid '
    +(claims.some(c=>c.severity==='loses')?'#96271F':'var(--ink-soft)')+'">'
    +'<b style="font-size:14px">Before you import — something this export assumes</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 10px">When this export was taken you '
    +'confirmed on the WordPress screen that these were already in this shop. The export plugin cannot '
    +'see this shop and did not check. If any of them is not true, read the note before importing.</p>'
    +claims.map(c=>'<div class="impmeta" style="margin-top:8px"><b>'+impEsc(c.needs)+'</b> was assumed to be '
      +'already imported when <b>'+impEsc(c.group)+'</b> was exported.'
      +(c.severity==='loses'?' <b style="color:#96271F">If it was not, rows will be lost and the report of '
        +'this run will not say so.</b>':'')
      +(c.claim?'<div style="margin-top:4px">'+impEsc(c.claim)+'</div>':'')+'</div>').join('')
    +'</div>';
}
```

It uses `impEsc` on every value, which matters: a manifest is a third-party file
and `ImportManifest` bounds these strings' length but does not escape them —
escaping is the view's job, as that class's own comment says.

**Run, not just written.** The function was extracted from this document and
exercised against the four payload shapes the endpoint actually produces: no
claim (`''`), no manifest at all (`''`, rather than a TypeError on
`s.manifest.groups`), a `loses` claim (red rule) and a claim whose text contains
`<this>` (escaped to `&lt;this&gt;`). The integrator is being handed a block that
has been executed, not one that has been proof-read.

### 7.4 `docs/WP-EXPORT-CONTRACT.md` — nothing

**No change is needed and none is proposed.** Everything this lane reads is
already in it, and `groups` arrives under the contract's own *"unknown keys are
ignored, never fatal"* clause exactly as GK's does. The integrator may want to
note, under `groups`, that **a group zip's manifest describes that group only and
several are merged on `export_id` by the importer** — but that is documenting
this shop's behaviour, not changing the format.

### 7.5 Nothing else

`routes/web.php`, `bootstrap/app.php`, `KBB-Master-Plan.md`,
`KBB-Progress-Dashboard.html` and `wordpress-plugin/` are untouched.

---

## 8. Every mutation, and its result

Thirty-five mutations, each applied to the source, the suite run, the source
restored. **Seven survived a first pass.** Two were bugs in this lane's own
rules, two were dead text now deleted, and three were missing tests. Two further
bugs — §4.4 and §4.5 — were caught by **Lane GF's existing suite** rather than by
a mutation of this lane's, which is the best argument there is for running the
whole thing rather than only this lane's file.

| # | mutation | first pass | now |
|---|---|---|---|
| M1 | the traversal segment check is deleted | red (3 tests) | red |
| M2 | the backslash check is deleted | red | red |
| M3 | the drive-letter check is deleted | red | red |
| M4 | the depth cap is deleted | red | red |
| M5 | the symlink check is deleted | red — and §3.2 for what it revealed | red |
| M6 | the per-entry declared-size cap is deleted | red | red |
| **M7** | **the total declared-size cap is deleted** | **GREEN — survived** | red — §8.5 |
| M8 | the streamed-byte cap is deleted | red | red |
| M9 | the entry-count cap is deleted | red | red |
| M10 | the allowlist accepts everything | red (2 tests) | red |
| M11 | the allowlist becomes a `.php` blocklist | red (2 tests) | red |
| **M12** | **the resolved-destination containment check is deleted** | **GREEN — survived** | **code deleted — §8.3** |
| M13 | the duplicate-destination check is deleted | red | red |
| M14 | the two-wrapper check is deleted | red | red |
| M15 | `CHECKCONS` is dropped from `open()` | red | red |
| M16 | the manifest replaces instead of merging | red (6 tests) | red |
| **M17** | **`groups.skipped` is unioned instead of intersected** | **GREEN — survived** | **the rule was changed to the union — §8.1** |
| **M18** | **two manifests with no `export_id` count as one export** | **GREEN — survived** | red — §8.2 |
| M19 | the merge drops the incoming `files` | red (3 tests) | red |
| M20 | the merge keeps only the incoming `groups.selected` | red | red |
| M21 | `assumed_already_imported` never reaches the screen | red (5 tests) | red |
| M22 | an unknown severity is guessed upward to `loses` | red | red |
| M23 | a claim with no `needs` is drawn half-built | red | red |
| M24 | the claim list is not merged across zips | red | red |
| **M25** | **the manifest is not sorted last inside a zip** | **GREEN — survived** | **code deleted — §8.4** |
| M26 | a zip is recognised by its extension, not its bytes | red | red |
| M27 | the entity dropdown is honoured for a zip | red | red |
| M28 | the scratch directory is never purged | red (2 tests) | red |
| M29 | a refusal mid-extract leaves the scratch behind | red | red |
| M30 | a file refused inside a zip discards the whole upload | red (2 tests) | red |
| M31 | the merge keeps the OLDER `files` entry | red — §4.4 | red |
| **M32** | **the merge keeps the OLDER `counts` entry** | **GREEN — survived** | red — §8.6 |
| M33 | Remove leaves the manifest describing the file | red — §4.5 | red |
| **M34** | **Remove throws the whole manifest away instead of one entry** | **GREEN — survived** | red — §8.6 |
| M35 | `merged_from` is unbounded | red — §4.6 | red |

### 8.1 M17 — the merge rule was wrong, and the mutation is what said so

`groups.skipped` was merged as an **intersection**, on the reasoning that the
Catalogue zip lists Orders as skipped and the Orders zip lists Catalogue as
skipped, so a union would claim an export carrying both carries neither.
Turning it into a union left all 47 tests green. The reasoning was wrong twice:

- **It was redundant.** GK's exporter writes `skipped` as every group that was
  not selected, so subtracting `selected` — which both rules do — already removes
  exactly the groups the two lists disagree about. For every input GK can
  produce, union-minus-selected and intersection-minus-selected are the same set.
  The intersection was doing nothing.
- **And where the two DO differ it was wrong.** A plugin that lists only the
  skips relevant to the group it is exporting — Catalogue says it left out
  Orders, Orders says it left out Reviews — has an **empty** intersection. Nothing
  carries reviews, so reviews genuinely IS skipped, and the intersection would
  have had the shop claim an export with no reviews in it had not skipped them.

The rule is now **union, minus everything selected**, and
`it keeps a group skipped when only one zip said so` is the test that
distinguishes them. Running M17 backwards — intersection in place of the union —
is now red.

### 8.2 M18 — `sameExport` was tested only on the easy side

`sameExport()` requires the left id to be non-null. Every test had one manifest
with an id and one without, so relaxing it to a bare `===` — which makes two
nulls equal — stayed green. `it does not merge two manifests that both refuse to
say which export they are` is the case that was missing: two anonymous manifests
are two **unknowns**, and merging them would join whatever arrives next to
whatever is already here.

### 8.3 M12 — the resolved-path check was a tautology, and it was deleted

`extract()` re-derived `dirname($destination)` and compared it to the scratch
root. Deleting it left the whole suite green, and **no test could have caught
it**: `$name` is a single validated segment by then, so comparing its dirname to
the directory it was just joined to is asking whether concatenation works.

It is **removed** rather than kept with a comment calling it belt and braces.
This repository has paid for a guard standing behind another one doing nothing
twice — `Api\ProductController`'s status filter, and GF's own second
`mismatch === null` — and dead text is worse than no text, because the next
reader counts it as protection.

The resolved path **is** checked, by **construction**, which is stronger than
checking it afterwards: `$real` is `realpath()` of the scratch directory so any
symlink on the way to it is already resolved; `$name` cannot be `.`, `..`, empty
or contain a separator; and the destination is exactly those two joined. There is
no input that produces a path outside `$real`.

Worth stating for the next reader, because the brief's own instruction was to
check the *resolved* path rather than the declared one: **refusing `..` outright
is the stronger of the two designs**, and it is stronger precisely because it
makes a resolved check redundant. A sanitising unpacker has to get path
resolution exactly right on every platform; a refusing one has to recognise two
characters. The comment in `ImportArchive::extract()` records all of this where
somebody would otherwise re-add the `if`.

### 8.4 M25 — an ordering that guaranteed nothing, with a comment saying it did

There was a `usort()` in `acceptArchive()` putting `manifest.json` last, under a
comment saying the manifest had to land after the files it describes.
**Reversing** it left every test green — correctly, because nothing in that loop
is order-dependent: `accept()` does not read the manifest when it takes a CSV,
`acceptManifest()` merges against what is on **disk** rather than against
anything in the batch, and one zip carries at most one manifest.

The comment read well and was not true of the code, which is the most expensive
kind of comment there is: the next reader would have preserved an ordering that
guarantees nothing while believing it guaranteed something. Both are gone, and
the replacement comment says why.

### 8.6 M32 and M34 — two survivors that were both about writing a weaker test

**M32** — swapping the `counts` merge back to first-wins stayed green, because
the fixture that made `files.rows` disagree had left `counts` alone. Nothing on
the screen reads `counts`, so it is asserted through the manifest file itself:
the owner can open that file, and this shop's own bookkeeping disagreeing with
the row counts beside it is a thing he would have to reconcile alone.

**M34** — the first attempt at this mutation deleted the manifest inside
`forgetManifestEntry()` and survived, and that turned out to be the mutation's
fault rather than the suite's: the rewrite two lines later put the file straight
back, so nothing had changed. Mutated where it actually bites — the rewrite
replaced by a delete — it is red, and `it stops describing a file the owner has
removed` now asserts the manifest is still **there** as well as shorter.
Recorded because "a mutation survived" and "a mutation did nothing" look
identical in a results table, and only one of them is a gap.

### 8.5 M7 — the cap that the other cap was hiding

Deleting the **running total** over declared sizes left the suite green, because
every hostile zip in it was a single member over the **per-entry** limit and the
per-entry check caught it first. Six members of 40MB each are individually fine
and together are 240MB. `it refuses members that fit one at a time but not
together` is the test that was missing.

---

## 9. Proving it

### 9.1 The suite

```
vendor/bin/pest                                         4,673 passed, 22 skipped
KBB_TEST_DB=kbb_gm vendor/bin/pest -c phpunit-mysql.xml  4,679 passed, 16 skipped
find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l    clean
```

Zero failures on either. The two run different numbers of tests because several
skip on SQLite and several others skip on MySQL, which is the point of running
both.

`tests/Feature/GmImportAcceptsZipTest.php` is 54 of those. `phpunit-mysql.xml`
already existed; `KBB_TEST_DB=kbb_gm` is this lane's own database, as its header
instructs, because two worktrees sharing the default drop the schema under each
other mid-run.

**A killed MySQL run leaves that database unusable, and the next run does not
repair it.** Interrupting one mid-migration left `kbb_gm` with a partial schema,
and the following run reported **1,986 failures** — every one of them
`SQLSTATE[42S02] ... Table 'kbb_gm.products' doesn't exist`, and none of them
anything to do with the code. `DROP DATABASE kbb_gm; CREATE DATABASE kbb_gm ...`
and it was green. Worth knowing before reading a four-figure failure count as a
catastrophe: one identical error repeated two thousand times is a broken
environment, not two thousand broken tests.

**`expect(...)->not->toContain()` passes vacuously**, and one had crept in. The
zip-slip test finished with

```php
expect(file_get_contents(base_path('.env')))->not->toContain('APP_KEY=stolen');
```

which reads as the most important assertion in the file — *the traversal did not
reach the application root* — and is the one that could never fail: `toContain`
is variadic, so negated it asserts "not ALL of these". It is now

```php
expect(str_contains((string) file_get_contents(base_path('.env')), 'APP_KEY=stolen'))
    ->toBeFalse('the zip wrote into the application root');
```

and that replacement was **proved live** rather than assumed: `APP_KEY=stolen`
was appended to `.env`, the test run, and it failed with that message — then
`.env` was restored. Every other "nothing was written" check is
`expect(gmEverythingUnderImport())->toBe([])`, an equality over the whole tree,
which cannot pass vacuously.

### 9.2 Two false failures that cost real time, and neither was a bug

Both are **pre-existing** and both fail in a way that looks like a bug in
whatever was being edited at the time, which is what makes them worth writing
down.

**Do not run two `pest` processes in ONE worktree.**
`AdminImportScreenTest`, `GfImportRefinementTest` and this lane's file all purge
`storage/app/import` in `beforeEach` and `afterEach`, so a second run deletes the
first's uploads mid-test and two tests fail with `ErrorException` on a file that
was there a moment ago. It is the filesystem twin of the warning in
`phpunit-mysql.xml`'s own header about two worktrees sharing a database.

**And `GeWpExporterTest` collides ACROSS worktrees, which the existing warning
does not cover.** Five of its tests failed in a full run here and passed one at a
time. The message was the tell:

```
PHP Fatal error: Uncaught PDOException: SQLSTATE[42S02]:
Base table or view not found: 1146 Table 'kbb_ge_wp.wp_options' doesn't exist
```

`kbb_ge_wp` is **hard-coded** as the default in
`wordpress-plugin/harness/run-export.php` and `screen.php` and passed literally as
`--db=kbb_ge_wp` from eight places in the test. Lane GL was running the same file
in its own worktree at the same time and rebuilding that schema underneath this
one. `phpunit-mysql.xml` solved exactly this problem for the Laravel suite with
`KBB_TEST_DB`, and the WordPress harness has no equivalent — so the protection
stops at the boundary where two lanes are most likely to be working on the same
tests.

`GeWpExporterTest` does touch one class this lane changed — it reads
`ImportManifest::read()` and `lists()` at line 1448 — so that was checked rather
than waved away: `git diff` on `ImportManifest.php` removes **no line at all**.
Every change to it is an addition (`raw()`, `groups()`, `sameExport()`,
`merge()`, `MAX_MERGED_FROM`), so `read()`, `parse()`, `files()`, `lists()` and
`rowsFor()` are byte-identical to what that test passed against before.

It reproduced every time the two runs overlapped, with between five and eight of
that file's tests failing and **the set changing from run to run** — which is the
same tell CLAUDE.md records for the full disk: a real data bug blames the same
row. `GeWpExporterTest` on its own, with the other lane idle: **31 passed, 1
skipped**. Across every overlapping run, the number of failures OUTSIDE that one
file was **zero**.

**For the integrator**: the fix is the one `phpunit-mysql.xml` already made — let
the harness database come from an environment variable with `kbb_ge_wp` as the
default. It is a change to `wordpress-plugin/`, which is Lane GL's, so it is
named here rather than made.

**And a warning about the obvious workaround, which this lane used and should
not have.** `pkill -f 'php vendor/bin/pest'` to get a clean run matches the
other lane's processes too, and killed Lane GL's suite mid-run. Nothing of GL's
was damaged — a killed test run leaves no state behind that its next run does not
rebuild — but it cost that lane a run it then had to repeat, which is a worse
outcome than the collision. Match on the worktree
(`pgrep -f pest | while read p; do readlink /proc/$p/cwd; done`) before killing
anything on a machine several lanes share.

This is also why CLAUDE.md's rule is worth restating with a second symptom: a
full disk looks exactly like a transaction bug, **and a second lane looks exactly
like a schema bug**. `df -h /` read 15G free throughout, which is what ruled the
first one out in a minute.

### 9.3 The hostile archives

They are **built, not checked in** — `tests/Support/ZipBuilder.php`. A member
named `../../../.env` is a file git would decline to check out, and a repository
whose worktrees already filled this disk once (CLAUDE.md's savepoint landmine)
does not want an 80MB fixture in it. `ZipArchive` also **normalises several of
the names away** before they reach the archive, so a test built with it alone
would be testing a name that is not the name the attack uses; the hostile members
are assembled from raw local-file headers and a central directory.

### 9.4 The browser

`tests/browser/gm-import-accepts-zip.mjs`, against a preview at
`http://127.0.0.1:8979`, signed in as the owner, reaching the screen through the
sidebar rather than by URL. Shots in `docs/gm-zip-shots/`:

| shot | what it shows |
|---|---|
| `01-import-screen-empty.png` | Store → Import, nothing uploaded |
| `02-catalogue-zip-unpacked.png` | one zip chosen; Categories, Brands, Products and the Export manifest all **ready** |
| `03-second-group-zip-added.png` | the Orders zip added; five files ready, the manifest still describing all five |
| `04-hostile-zip-refused.png` | the red banner — *"That zip contains an entry that tries to write outside the import folder (`../../../../products.csv`) ... It has not been unpacked."* — with the five earlier files untouched beside it |
| `05-ready-to-import.png` | the choices and the Import button |
| `06-imported-from-the-zips.png` | after the run: every entity 100%, every denominator from the manifest |

The console transcript of that run:

```
after catalogue.zip — files in manifest: [ categories.csv, brands.csv, products.csv ]
  groups selected: [ catalogue ]
  whole-export total: 20 over 3 files
after orders.zip — files in manifest: [ categories.csv, brands.csv, products.csv,
                                        orders.csv, order_items.csv ]
  groups selected: [ catalogue, sales ]
  groups skipped:  [ customers, reviews, coupons, seo, content, addresses ]
  the operator's unverified claim: [{"group":"sales","needs":"customers",
    "severity":"loses","claim":"Customers was already imported into the new shop
    when this export was taken. The operator stated this; the plugin cannot see
    the other shop and did not check it."}]
  whole-export total: 37 over 5 files
  denominators: categories=7/manifest brands=3/manifest products=10/manifest
                orders=10/manifest order-items=7/manifest
refusal shown on screen: That zip contains an entry that tries to write outside
  the import folder ("../../../../products.csv"). ...
files still present: categories brands products orders order-items
run: complete
per-entity: categories 100% (manifest) · brands 100% (manifest) · products 100%
            (manifest) · orders 100% (manifest) · order-items 100% (manifest)
```

The preview needed a front controller of its own — `public/index.php` is not in
this repository, because `bootstrap/app.php` points the public path at a
different directory on the shared host. It was written for the run and removed
at hand-back; the script's header says how to recreate it.

---

## 10. What only the owner or the integrator can settle

1. **Does every group zip of one export carry the same `export_id`?** This is the
   one assumption in §2 with a consequence. It is what GK's design implies and
   what the brief states, and the merge is built on it. If GL gives each zip its
   own id, each upload replaces the last manifest, and every group but the last
   silently loses its truncation check (§4.1). **One line in
   `docs/GL-GROUP-DOWNLOADS.md` settles it.**

2. **What should the *Addresses and pictures* group do?** Its two files —
   `permalinks.csv` and `media.csv` — are contract **gap** files with no importer
   on this screen, so that zip unpacks and both files are then refused by name.
   Not a regression and not this lane's to fix, but the owner will download that
   group and it will not import. Somebody has to decide whether the screen names
   it up front, or an importer arrives, or the export screen says so.

3. **`GeWpExporterTest`'s harness database needs a `KBB_TEST_DB` of its own.**
   §9.2. It is a change under `wordpress-plugin/`, so it is Lane GL's or the
   integrator's, not this lane's — but while it is unfixed, any two lanes running
   the suite at once produce five to eight failures in that file that belong to
   neither of them, and a lane that does not know this will go looking for the
   bug in its own diff.

4. **Is one wrapping directory enough tolerance?** Two levels and two sibling
   folders are both refused with a sentence rather than flattened (§2). If GL has
   a reason to nest deeper, this side moves — but flattening silently is the one
   thing it will not do.

5. **The 64MB cap covers the whole zip, expanded.** A group zip is a handful of
   CSVs so this is generous today, but a catalogue of 40,000 products with long
   descriptions would reach it, and the refusal sentence says to export the group
   in pieces. Whether the shared host would even accept a 64MB upload is a
   separate question its own `upload_max_filesize` answers, and the screen already
   shows both numbers.

6. **Should an `assumed_already_imported` claim ever be something the owner has
   to acknowledge before Import?** This lane made it a notice and §5 argues why.
   A tick box would be defensible for the single `loses` edge and nothing else —
   but it is a change to the screen, and the decision belongs with whoever watches
   the owner use it.

7. **The three blocks in §7 are the difference between "the data is there" and
   "the owner sees it".** 7.1 and 7.2 are polish — the zip works without them,
   through drag-and-drop or *All Files*. **7.3 is not**: until it is applied, the
   operator's unverified claim is carried in the payload and drawn nowhere, and
   §5's argument for showing it is an argument rather than a description of the
   screen.

---

## 11. Reproducing this

```bash
git worktree add ../kbb-gm lane/import-accepts-zip
cd ../kbb-gm
git config user.email noreply@anthropic.com && git config user.name Claude
cp -al ../kbbstore/vendor vendor          # hard-linked; a symlink resolves
cp ../kbbstore/.env .env                  # Composer's $baseDir to the main repo

vendor/bin/pest tests/Feature/GmImportAcceptsZipTest.php
vendor/bin/pest
KBB_TEST_DB=kbb_gm vendor/bin/pest -c phpunit-mysql.xml

# the browser run needs a preview front controller, which this repo has no copy
# of — see tests/browser/gm-import-accepts-zip.mjs's header.
```

`git worktree remove --force ../kbb-gm` when the branch is merged. CLAUDE.md's
savepoint landmine is a lane's leftover worktrees filling the disk, and `df -h /`
before debugging any intermittent database error.
