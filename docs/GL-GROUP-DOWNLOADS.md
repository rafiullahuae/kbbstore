# One downloadable zip per group · Lane GL

The owner's words, after Lane GK gave the export screen eight tickable groups:

> *"you didn't group. please group it, allow to download each group seperate
> files. so will have no any heavy file. please do it properly and group these
> according."*

He is not asking for the selection. GK built that and it is right. He is asking
about the **output**: every group wrote its CSVs into one folder under
`wp-content/uploads/kbb-export/<id>/` and the screen's instruction was *download
the folder over FTP*. That is one heavy thing, fetched with a second tool, by
somebody who has no shell and never asked for an FTP client.

So: **one zip per group, downloaded from the browser, each one a complete import
on its own.**

The evidence is `tests/Feature/GeWpExporterTest.php` — **41 tests, 1,028
assertions**, nine of those tests this lane's — the measurement is
`wordpress-plugin/harness/volume.php`, and the screenshots are
`docs/gl-download-shots/`.

---

## 1. The headline

| | |
|---|---|
| downloads | **one zip per group**, from a button on the screen, no FTP |
| each zip | a complete `kbb-export/1` export: unpack it, point `kbb:import` at the folder |
| shared identity | every zip of one export carries the **same `export_id`** — §4 |
| the shape | entries at the archive **root**, no wrapping directory — §2, and it is a contract with Lane GM |
| measured size | largest group at real volume is **Orders, 2.16 MB**; whole folder 15.85 MB — §3 |
| splitting | **nothing needs it**, stated with figures rather than assumed. The splitter exists, is reached by a test, and does not fire on this shop — §3.3 |
| one bounded unit per request | one file into one archive, browser-driven exactly as the export's batches are. Slowest unit at real volume: **293 ms** — §5 |
| download safety | capability + its own nonce + **no path from the request** + the folder guard intact — §6 |
| honesty | a group that was not exported has **no button**, and says so — §7 |
| no Composer, no build | one new plugin file, plain PHP 7.4, `ZipArchive` only, and legible where it is absent — §8 |
| mutations | **25 broken, 2 survived first time, all 25 red now** — §9 |
| unchanged | GK's selection UI, dependency warnings, live bars, `ImportWorkspace` labels; media still travels as links inside `products.csv` / `posts.csv` — §10 |
| found on the way | the harness MySQL database was named by a literal in fifteen places, so two lanes running this file at once dropped each other's schema. Now `KBB_WP_DB`, default unchanged — §11.3 |
| suites | SQLite **4,629 passed, 21 skipped** (34,844 assertions); MySQL **4,635 passed, 15 skipped** (35,170 assertions) |

---

## 2. The zip shape — exactly, for Lane GM

Lane GM is teaching the Laravel import screen to accept one of these. This is the
half of the contract the WordPress side owns, and it is stated rather than
discovered, for the reason `docs/WP-EXPORT-CONTRACT.md` gives about itself: *a
format invented twice is a format that does not meet in the middle.*

**Archive name**

```
kbb-export-<group>-<first 8 hex of export_id>.zip
kbb-export-<group>-<first 8 hex of export_id>-part<N>of<M>.zip     (only when split)
```

for example `kbb-export-sales-8f14e45f.zip`. The group is in the name because the
owner asked for it to be — he will have several of these in one Downloads folder
at once, and `export.zip (3)` is not something anybody can import in the right
order. The export id's first block is there because two exports of the same group
on one day would otherwise be the same name and the browser silently renames the
second.

**Inside**

```
kbb-export-sales-8f14e45f.zip
├── orders.csv
├── order_items.csv
├── refunds.csv
├── order_notes.csv
└── manifest.json
```

- **Entries are at the archive root. There is NO wrapping directory.** Unzipping
  into an empty folder produces exactly the directory layout `ImportRunner`
  already takes, with no path fixing on the Laravel side.
- The entries are **that group's CSV files under their contract names**, plus
  **one `manifest.json`**.
- **Nothing else is in the archive.** No `index.php`, no `.htaccess`, no uploads
  folder, no other group's files. The first two matter: they are the export
  folder's guard, and sweeping the folder into a zip would carry a deny-all
  `.htaccess` to wherever the owner unpacks it.

`it puts the group files at the archive root with no wrapping directory` asserts
all of it, including the *nothing else* half, with `array_diff` in both
directions — `expect(...)->not->toContain()` passes vacuously because `toContain`
is variadic, and this is precisely the assertion that idiom would have silently
voided.

**Which group holds which file** is Lane GK's table, unchanged
(`docs/GK-EXPORT-GROUPS.md` §2), and `KBB_Export_Groups::all()` is still the one
declaration. This lane added no file to any group and moved none.

---

## 3. What each group weighs, at the real shop's volume

### 3.1 Why this had to be measured

*"so will have no any heavy file"* is a claim about **size**, and the decision it
drives — does a group need splitting into numbered parts? — cannot be made at the
fixture's volume. `tests/Fixtures/kbb-export` is five products and three orders.
The shop is 671 products, 4,159 orders, 10,571 line items, 3,712 customers and
2,514 reviews: four orders of magnitude away.

Building a splitter for a file that turns out to be 400 KB is building something
nothing uses. *Not* building one for a file that turns out to be 40 MB is handing
him exactly the heavy file he asked not to have. So it was measured first.

### 3.2 The measurement

`wordpress-plugin/harness/volume.php` builds every one of the seventeen CSVs at
the real shop's volume and zips each group **through `KBB_Export_Zip` itself** —
the shipped class, one bounded unit at a time, not a `zip -r`.

What is real in it: the **column set of every file**, read out of the fixture the
plugin wrote, so these are the plugin's own headers and not a retyped guess; the
row counts; the grouping; the plan; the compression.

What is synthesised: the cell contents, because 671 real products are not in this
repository and cannot be. This matters for exactly one reason and the generator
is careful about it — **zip size is a function of entropy**. A generator writing
`str_repeat('x', 3000)` into every description would compress to nothing and
report a flattering figure that means nothing. So text cells are built from a
word pool with the statistics of English prose and real HTML around them, ids and
prices and dates vary per row, emails and bcrypt hashes are high-entropy the way
real ones are, and repeated values (statuses, countries, currencies) repeat.

```
php wordpress-plugin/harness/volume.php
```

| group | files | raw CSV | **zip** | ratio |
|---|---|---:|---:|---:|
| Catalogue | categories, brands, tags, attributes, products, variations | 3.33 MB | **710 KB** | 20.8% |
| SEO (Yoast) | seo | 78.5 KB | **21.5 KB** | 27.3% |
| Coupons | coupons | 23.6 KB | **8.1 KB** | 34.4% |
| Customers | customers | 1.75 MB | **638 KB** | 35.6% |
| **Orders** | orders, order_items, refunds, order_notes | 7.98 MB | **2.16 MB** | 27.1% |
| Reviews | reviews | 1.42 MB | **366 KB** | 25.2% |
| Journal articles | posts | 99.2 KB | **25.8 KB** | 26.0% |
| Addresses and pictures | permalinks, media | 1.18 MB | **252 KB** | 20.9% |

**Whole folder 15.85 MB. Largest single download 2.16 MB. 25 bounded units,
slowest one 293 ms.**

The one genuinely uncertain input is how long a product description is, because
it is the largest single contributor and it is a property of how the owner
writes. It is a flag, and the sensitivity run is:

| descriptions | Catalogue zip | largest zip anywhere |
|---|---:|---:|
| 1 KB | 427 KB | 2.16 MB (Orders) |
| 3 KB (the table above) | 710 KB | 2.16 MB (Orders) |
| 6 KB | 1.10 MB | 2.16 MB (Orders) |
| 12 KB | 1.79 MB | 2.16 MB (Orders) |

Orders is the largest group across the whole range, which is the shape the brief
predicted — *"and if `orders` does, that is the one."* It does not.

### 3.3 The decision: nothing needs splitting, and the splitter stays

**Nothing in this shop needs splitting.** The largest download is 2.16 MB, which
is smaller than a single product photograph and is not an event on any
connection. Saying so with the figures is the answer the brief asked for.

The splitter is kept anyway, and **not** because it might be needed one day. The
cap is the only thing standing between this feature and its own failure mode: a
browser giving up part way through a 40 MB response on a shared host's timeout,
which looks to the owner exactly like the export having failed, and which arrives
without warning the first time the shop has grown enough.

`KBB_Export_Zip::PART_CAP_BYTES` is **25 MiB of CSV** — a cap on the
*uncompressed* input, because the size of the download is not knowable until the
compression is done, by which time splitting means doing it again. 25 MiB at the
**worst measured ratio (35.6%, Customers, because a bcrypt hash per row is 60
characters of base64 that deflate can do nothing with)** rounded to 40% for a
shop that is not this one, is a ~10 MB download. That is the largest thing worth
handing somebody on shared hosting in one piece.

**Whole files, never half of one.** Splitting `orders.csv` down the middle and
putting `order_items.csv` beside one half would produce a part whose line items
point at orders that are not in it. A file that is on its own over the cap gets
its own part and exceeds it — a slow download is recoverable, a wrong import is
not.

A guard nothing can reach is a guard nothing checks, so
`it splits a group that would be a heavy download, whole files at a time` hands
`parts_for()` a manifest asserting `orders.csv` is 30 MB. It reads nothing but
`bytes`, so that is the whole of what a 30 MB file would give it — no fixture, no
MySQL, and it runs in CI. Orders comes out in two parts, `orders.csv` alone in
one and the other three in the other, and no CSV is in more than one part.

`it weighs every group at the real shop volume` runs the measurement in the suite
and fails if any group crosses the cap, so the finding above cannot quietly stop
being true.

---

## 4. Each zip is independently importable — and they are still one export

### 4.1 Why not a slice of a folder

A zip that is a slice of a folder is not a deliverable, it is a chore: the
operator would have to unpack eight of them into one directory before the new
shop could read any of it, and the first one unpacked in the wrong order would
cost him the customer rows `docs/FV-IMPORT-AT-VOLUME.md` §6 measured.

So **every zip carries its own `manifest.json`**, which makes it a complete
`kbb-export/1` export in its own right.

### 4.2 What that manifest says

Copied unchanged from the export's own manifest: `format`, `export_id`,
`generated_at`, `source`.

**Narrowed to this archive's files:** `files` and `counts`. This is the
contract's own absent-versus-`"rows": 0` rule applied one level down — a zip that
does not carry `customers.csv` must not list it with `"rows": 0`, because the
shop reads that as *this shop has no customers* and it means *this archive does
not carry them*. Lane GK made that distinction load-bearing at the folder level;
this is the same distinction inside the archive.

**Rewritten from this archive's point of view:** `groups.selected` is this group
alone, `groups.skipped` is every other group, because that is what an importer
pointed at this folder is looking at. `groups.assumed_already_imported` travels
with **every** zip rather than only the one it was made against — he may import
these on different days, and the claim that customers were already in the new
shop is the reason this export has orders and no customers, so it has to be
readable from whichever archive he opens first.

**One new key, `zip`:**

```json
"zip": {
  "group": "sales",
  "group_label": "Orders",
  "part": 1,
  "parts": 1,
  "of_export": ["catalogue", "seo", "coupons", "customers", "sales", "reviews", "content", "addresses"],
  "archive": "kbb-export-sales-8f14e45f.zip"
}
```

It is legal under the contract's own rule — *"Unknown keys are ignored, never
fatal"* — and nothing reads it today. It exists so a person or a future importer
holding **one** archive can answer *is there more of this, and how much* without
being told. §11 is the note to the integrator about whether the shop should act
on it.

**And the same facts in words, in `notes`,** which is what the owner actually
reads on the import screen. The archive's own sentence is prepended; the export's
notes are kept, because they are facts about the **shop** and are no less true of
a slice of it:

```
This archive carries ONE GROUP of export 15402bdc-…: Orders (orders.csv,
order_items.csv, refunds.csv, order_notes.csv). The same export also produced:
Catalogue, SEO (Yoast), Coupons, Customers, Reviews, Journal articles, Addresses
and pictures. Every archive of this export carries the same export_id, which is
how this shop can tell they are parts of one export rather than several. Import
them in the order they are listed on the export screen — that order is not
cosmetic, and importing orders before the customers they belong to costs
customer rows.
```

One wrinkle, stated rather than tidied away: the export's own notes include a
sentence of the form *"Every group was exported: Catalogue, SEO (Yoast), …"*,
which is a fact about the **export** and not about the archive it now sits in.
It is kept, because deleting it would leave the archive unable to say what the
export carried, and the archive's own first sentence — which names this group and
lists the others — reads before it. Rewriting the export's notes per archive was
the alternative and was rejected: the notes are how the owner learns what the
export decided, and an archive that paraphrases them is an archive that can
paraphrase them wrong.

### 4.3 `export_id` is what makes eight downloads one export

`docs/WP-EXPORT-CONTRACT.md`: *"`export_id` identifies one export."* Eight
archives that are each a complete `kbb-export/1` export would otherwise read as
eight unrelated exports, and the duplicate guard the contract exists to provide
would have nothing to join them on. So it is **copied, never re-minted**, and
`it gives every archive of one export the same export id` asserts it against the
folder's manifest.

### 4.4 The round trip, from inside the archives

Lane GK proved that eight separate **exports** land what the all-at-once export
lands. This is the claim one level out, and it is the one the owner is making
when he downloads eight files: that **one export's eight archives**, unpacked one
at a time into folders of their own, land the same thing.

It is not the same claim, and the difference is the manifest. A group's archive
carries a manifest this lane **built**, narrowed to its own files, rather than
the one the export wrote. If that narrowing were wrong, every CSV could be
perfect and the import would still read the wrong denominator or be told the shop
has zero customers when the archive simply does not carry them.

`it packs one zip per group, and each one imports on its own into what the whole
export lands` therefore:

1. runs one whole export, which produces eight archives;
2. unpacks each into a folder of its own;
3. checks every CSV inside every archive is **byte-identical** to the one in the
   folder, which is byte-identical to the fixture — zipping changes *where* a
   file is and nothing about *what is in it*;
4. feeds each unpacked folder to the **real `ImportRunner`**, in the order the
   screen lists them, into one database, with **no refusals** beyond the one row
   this repository has already written down as declined by design;
5. and asserts the shop holds exactly what the full manifest's `counts` say —
   plus the thing that separates *the rows are there* from *the shop works*: no
   order line with a null `product_id`, no order without a customer, and exactly
   one synthesised guest. Eight separate downloads is precisely the situation in
   which somebody does them in the wrong order, so the foreign keys are checked
   **across archive boundaries**.

---

## 5. One bounded unit per request

The export is batched because this host kills a request at 110 seconds. A zip
phase that compressed 10,571 order lines inside the request that finished the
export would put that limit straight back, one phase later.

So **the zip phase is a second browser-driven loop**, and **one unit is one file
into one archive**. `KBB_Export_Zip::plan()` flattens the whole job into that
list; `KBB_Export_Runner::zip_step()` does one and advances a cursor in the same
option the export's checkpoint lives in. A request that dies costs one file.

Re-opening the archive per unit is not the expensive thing it looks like: libzip
copies the entries already there as **compressed bytes** and only compresses the
one being added. At the real shop's volume the slowest single unit is **293 ms**,
and `it weighs every group at the real shop volume` fails above ten seconds.

Two smaller decisions that are deliberate:

- **`done` keeps its existing meaning** — every CSV written and the manifest
  closed. The folder is a complete, importable export at that moment whether or
  not anything is zipped, and every test and every reader that already depends on
  that sentence is right. The zip phase reports itself separately, in `zip`.
- **The units are ordered by group**, in the declared group order, so a zip phase
  interrupted half way has *whole* archives for the early groups rather than
  eight half-written ones. Same reasoning that puts the catalogue first in the
  stage list.
- **`manifest.json` goes into each archive last**, after every file it describes
  is closed, which is the contract's rule for the folder and no less true of the
  archive.

---

## 6. The download security model

`customers.csv` carries every shopper's address and their WordPress password
hash. `reviews.csv` carries the reviewer's email and the IP they posted from —
the exact pair CLAUDE.md names as having leaked out of an unauthenticated
`/api/*` route. Zipping them does not make them less of a secret; it makes them a
**single file with a predictable name**, which is strictly easier to fetch than
seventeen.

**Five things, and none is sufficient alone.**

**1. The archive lives inside the already-guarded folder.** It is written into
`uploads/kbb-export/<random id>/`, which carries an `index.php`, whose parent
carries an `index.php` and a deny-all `.htaccess`. Nothing is written to the
uploads root or to a "downloads" folder. `it does not undo the folder guard by
putting an archive in it` reads the three guard files back **after** the archives
are written, checks the `.htaccess` still says `Require all denied` *and* `Deny
from all` (the deny is unconditional and covers the whole folder, which is what
makes it cover a file type that did not exist when it was written), and counts
**8 archives inside the guarded folder and 0 outside**.

**2. It is served by WordPress, not by the web server.** The link goes to
`admin-post.php?action=kbb_export_download`, registered on `admin_post_<action>`
— which only a logged-in user reaches — and **deliberately not** on the `nopriv`
twin. A link straight into `wp-content/uploads` would have no WordPress in the
path at all, and the only thing between a stranger and every password hash would
be nobody having guessed the folder name.

**3. Capability, checked in the handler.** `manage_woocommerce` (or
`manage_options`), checked in `download()` and not merely on the menu entry —
`add_management_page()` decides what is in a menu, not what answers a URL.

**4. Its own nonce.** `DOWNLOAD_NONCE` is a separate action from the export's.
The export nonce lives in a POST body; a download has to be a GET, because a
browser saves a navigation and not a `fetch`, so its nonce ends up in a URL —
which is a place URLs get: history, referrers, screenshots, a photograph of the
screen he is asking for help with. A separate action means a leaked download URL
cannot be replayed as a request to *start* an export, and vice versa.

**5. No path from the request ever reaches the filesystem.** The request carries
a **group key and a part number and nothing else**. The folder comes from the
runner's own state; the file name is computed by `zip_name()` from that state's
export id. Nothing the browser sent is concatenated into a path, so there is no
traversal to sanitise rather than a sanitiser to get right. The group is checked
against the plugin's own declaration and the part against the plan.

**And one answer for every way of not having it.** A group that was never
exported, a group key that does not exist, a part that was never planned, and
`../../../../etc/passwd` all get **the same sentence and the same 404**, so the
endpoint cannot be used to ask which groups this shop exported. That is the shape
CLAUDE.md requires of `QuizSubmission::findByPublicToken()` and for the same
reason: branching differently on the two restores the oracle. The test compares
the four **against each other** rather than against a literal, so changing the
sentence keeps the property and diverging on any one of them fails.

Precisely: there is **one** other answer, and it is deliberate. A group this
export *did* carry, whose archive is still being packed, gets its own sentence —
*"That archive has not been packed yet."* — because by then the caller has passed
both the capability check and the nonce check, is the shop manager, and the
useful thing to tell him is the true thing. The four indistinguishable cases are
the four that could otherwise answer *which groups did this shop export* to
somebody who should not be asking.

### 6.1 And it streams — measured, not asserted

`download()` reads the archive in 8 KB chunks with every output buffer torn down
first, rather than `file_get_contents()`. On a 4 KB fixture archive the two
spellings behave identically, so *"it streams"* would have been a comment and the
mutation that replaced it would have survived every test in the suite — which is
exactly the hole `docs/GK-EXPORT-GROUPS.md` §6.1 paid for once already.

So it is measured. The archive is padded with **96 MB of incompressible, STORED
bytes** and the endpoint is run in a process capped at **64 MB**. Streaming
survives and the streamed bytes are checked to be a valid archive still
containing `products.csv` and `manifest.json`. Reading the file whole is a fatal
error — M14 in §9, and it is red.

---

## 7. The screen stays honest about what it has

A group that was not exported has no archive, and the row **says so** instead of
offering a button that 404s. A 404 tells him nothing about which of three reasons
it is, and the screen knows all three:

| state | what it draws | when |
|---|---|---|
| `ready` | a real link, with the archive's name and its size | the archive is on disk |
| `building` | a disabled button, *Packing…* | the export is done, the zip phase has not reached this group |
| `absent` | a disabled button, *Not in this export*, and a sentence naming the group | the group was not in this export |

`absent` is drawn as a **row that is there and disabled**, not as a row that is
missing — a table with three of eight groups in it reads as a bug.

The table is also **rendered from the server on page load**. He will close the
tab and come back; a download table that only exists in the page that started the
export is a table he cannot reach. A finished export whose archives are not all
packed picks up where it was left, from the cursor in the option, and
re-exports nothing.

Screenshots in `docs/gl-download-shots/`:

- `01-at-rest-no-export.png` — no export yet, no download table.
- `02-after-a-run.png` — a **real** export of three groups and a real zip phase,
  with the page rendered on top of the state it left: three Download links with
  names and sizes, five *Not in this export*.
- `03-downloads-after-a-live-run.png` — the same table drawn by the live loop,
  mid-session.

---

## 8. `ZipArchive`, and what happens where it is absent

No Composer dependency and no build step: the plugin installs by uploading a zip
through **Plugins → Add New**, which is the only door this host has. PHP's own
`ZipArchive` is therefore the only sane choice — and it ships with nearly every
PHP build, but *nearly* is not *every*, and a shared host with `ext-zip` disabled
is a real thing.

Where it is absent, this **does not fatal and does not pretend**:

- `KBB_Export_Zip::available()` answers false;
- the runner **seeds no queue and skips the phase** rather than dying half way
  through an export that has already written every CSV correctly;
- the screen prints one sentence naming the missing extension, next to the folder
  path, and the FTP route that was the only one before this lane still works.

A missing zip extension costs the convenience. It must not cost the export.

The new plugin file is plain PHP 7.4 and
`it stays inside the PHP version its header claims` covers it with the rest.

---

## 9. Mutation testing

Each guard was broken, `vendor/bin/pest tests/Feature/GeWpExporterTest.php` run,
and the guard restored.

**25 broken, 2 survived first time, all 25 red now.** Nineteen in the plugin's
PHP, six in the screen's JavaScript — which needs the browser harness, because
the PHP suite cannot see a line of it.

| # | mutation | verdict |
|---|---|---|
| M1 | every archive gets the export's own manifest instead of its own | red |
| M2 | the files go into a wrapping directory inside the archive | red |
| M3 | `group_manifest()` keeps the whole export's `files` block | red |
| M4 | each archive mints its own `export_id` instead of copying | red |
| M5 | a group the export did not write still gets an archive | red |
| M6 | `parts_for()` never splits | red |
| M7 | `parts_for()` packs a part straight past the cap | red |
| **M8** | **`files_in_part()` ignores which part is being asked for** | **GREEN — survived**, then red (§9.1) |
| M9 | `status()` reports an archive ready that is not on disk | red |
| M10 | `status()` says *building* for a group that was never exported | red |
| M11 | the archive name drops the group it is for | red |
| M12 | the zip cursor skips a unit | red |
| **M13** | **one request drains the whole zip queue** | **GREEN — survived**, then red (§9.2) |
| M14 | a group that exists but was not exported gets its own answer | red |
| M15 | the archive is written outside the guarded folder | red |
| M16 | the download drops its capability check | red |
| M17 | the download drops its nonce check | red |
| M18 | the download reads the whole archive into memory | red |
| M19 | the archive manifest stops saying it is one group of an export | red |
| M20 | the screen stops looping the zip phase after the first unit | red |
| M21 | the screen draws every group's download as ready | red |
| M22 | the download link is built without the nonce | red |
| M23 | a group with no archive is left out of the table instead of saying so | red |
| M24 | a finished export never starts the zip phase | red |
| M25 | the screen stops drawing the table from the server on load | red |

M20 through M25 were only *possible* to write because Lane GK had already built
`harness/screen.php` and `harness/screen-drive.mjs`. Without them every one of
the six would have survived, exactly as GK's M13 did — the download table, the
three states and the packing loop are all JavaScript, and the PHP suite cannot
see any of it.

### 9.1 M8 — the splitter's arithmetic had a hole only a split could show

`KBB_Export_Zip::files_in_part()` answers *which files are in part N of this
group*. Making it ignore N and always answer **part 1** left the whole suite
green.

The reason is the finding in §3: **nothing on this shop splits**. Every group has
exactly one part, so `$parts[0]` and `$parts[$index]` are the same list, and the
splitter test — which reads `plan()`, computing its parts separately — could not
see the difference.

What that mutation produces is the worst thing an archive can be: a **part 2
holding `order_items.csv` whose `manifest.json` says it holds `orders.csv`**.
Every fact the shop reads about that archive would then be about a file that is
not in it — the wrong row count, the wrong `sha256`, the wrong entity name.

The fix is general rather than a grep for that one call: the probe now reports a
manifest **per part**, keyed `<group>:<part>`, and the splitter test asserts each
part's manifest describes that part's files, that its `zip.part` and archive name
agree, and that the two parts between them describe every file of the group
**exactly once**. A third part added later is checked by the same loop.

### 9.2 M13 — and the first attempt at it was a bad mutation, not a green guard

The guard is *one bounded unit per request*. The first attempt replaced the
`add()` call with a loop over the remaining plan — and left the line below it,
which advances the cursor by one, untouched. So the harness still made one call
per unit, still counted `zip_steps == units`, and reported **green**.

That was a defective mutation and not a missing guard, and it is recorded here
because the difference matters: a mutation that does not change the behaviour it
claims to change is a false negative that reads exactly like a hole. Re-expressed
so that it really drains the queue — every remaining unit added, cursor jumped to
the end — it is red, on
`expect($report['zip_steps'])->toBe($report['zip']['units'])`.

---

## 10. What was confirmed rather than rebuilt

**Lane GK's screen is untouched.** The selection UI, the dependency warnings and
their two severities, the *Add &lt;Group&gt;* buttons, the confirmation
checkboxes, the Start-stays-disabled rule, the per-group bars and the
`ImportWorkspace`-matched labels are all still there and still pass all ten of
GK's tests plus the browser test. This lane added a Download section **below**
them and a second loop **after** the export's; it changed no line of the
selection logic and no group's file list.

**Media is still right and is still untouched.** The image **links** travel
inside `products.csv` and `posts.csv`, and `MediaSideloader` downloads the files
itself. `media.csv` is in *Addresses and pictures* and nothing this lane did
moved it. `it still carries the picture links inside the CSVs` is GK's test and
still passes; the new archive-shape test additionally asserts that no archive
carries an uploads folder or any picture file, so a zip cannot quietly become the
thing that ships images.

**`wordpress-plugin/` still cannot ship, under both locks.**
`UpdateGuard::ALLOWED_PREFIXES` does not name it, so `checkPath()` answers *"Path
outside the permitted areas"* — measured for every PHP file of the plugin,
including this lane's new `class-kbb-export-zip.php`, which the iterator picks up
automatically. `BuildPackage::NEVER_SHIP` names `wordpress-plugin/`, asserted
through reflection. `wordpress-plugin/harness/volume.php` is named explicitly
alongside GK's three, because a file called "volume" that reads a fixture and
writes a temp folder is exactly the sort of thing somebody moves into `tests/`.

---

## 11. For files this lane may not edit

### 11.1 `docs/WP-EXPORT-CONTRACT.md` — the integrator's, and **nothing is required**

This lane has not diverged from the contract. The `zip` key is legal under the
contract's own rule — *"Unknown keys are ignored, never fatal. A newer plugin
writing a field this shop does not read must not stop an import."* — and the
absent-versus-`"rows": 0` distinction the per-group manifest leans on is already
written down and already read by `ImportManifest::lists()`. `export_id`
identifying one export is the contract's own sentence, used rather than extended.

What the integrator **may** want is to document the key, so the next reader knows
it exists. It is a documentation addition; the format is unchanged and no code
depends on it.

Anchor — the whole line, verified **1 occurrence** in `docs/WP-EXPORT-CONTRACT.md`:

```
- **Unknown keys are ignored, never fatal.** A newer plugin writing a field this
```

Insert the following **immediately before** that anchor line, leaving the anchor
itself untouched. It **inserts**; no existing line changes:

```markdown
- **An export may also arrive as one zip per group.** The plugin packs each
  exported group into `kbb-export-<group>-<short id>.zip` beside the CSVs. Each
  archive holds that group's CSV files AT THE ARCHIVE ROOT — no wrapping
  directory — plus a `manifest.json` of its own whose `files` and `counts` are
  narrowed to the files in that archive, so the absent-versus-`"rows": 0` rule
  above holds inside an archive exactly as it holds in a folder. Every archive of
  one export carries the SAME `export_id`, which is how this shop tells parts of
  one export from several exports. A `zip` key records `group`, `part`, `parts`
  and `of_export`; nothing reads it yet and, per the rule below, it is ignorable.
  `docs/GL-GROUP-DOWNLOADS.md` is the account.
```

### 11.2 `docs/IMPORT-RUNBOOK.md` — how to fetch an export now

§9b describes fetching the export folder over FTP. **Inserts a new paragraph**;
no existing line changes.

**Note for the integrator:** Lane GK's §7.2 proposes an insert before this same
anchor line. Both are inserts and neither changes the anchor, so applying GK's
first leaves this one still at **1 occurrence** — but they must be applied in
that order, and the anchor re-counted between them.

Anchor — the whole line, verified **1 occurrence** in `docs/IMPORT-RUNBOOK.md`:

```
`manifest.json` is written LAST, so a folder without one is an export that did
```

Insert the following **immediately before** that anchor line, leaving the anchor
itself untouched:

```markdown
You no longer need FTP to fetch it. When the export finishes, the screen packs
one zip per exported group and offers a Download button for each — every archive
is a complete import on its own, so unpack one into an empty folder and point
`kbb:import` at that folder. Import them in the order the screen lists them: that
order is not cosmetic, and importing orders before the customers they belong to
costs customer rows. Every archive of one export carries the same `export_id`, so
the shop can tell they are parts of one export. Delete the folder from the server
once they are all downloaded — the archives hold the same password hashes and
reviewer IPs the CSVs do. `docs/GL-GROUP-DOWNLOADS.md` §2 has the archive shape.
```

### 11.3 One thing every lane needs: the harness database was shared

Not this lane's code, found by this lane, and it costs somebody an afternoon
every time it happens.

`wordpress-plugin/harness/shop.php` **drops and rebuilds** every table it uses,
and `tests/Feature/GeWpExporterTest.php` named that database with a literal —
`kbb_ge_wp`, in fifteen places. Two worktrees running this file at once therefore
tear the schema down under each other mid-run, and the failure reads as a defect
in whichever lane was inside `kbb_harness_build()` at the time:

```
Base table or view not found: 1146 Table 'kbb_ge_wp.wp_options' doesn't exist
```

Measured, not theorised: three tests in this file failed exactly that way during
a full-suite run while a sibling lane's suite was running, and the same three
passed alone immediately before and after.

`phpunit-mysql.xml` already carries this exact warning about the **Laravel**
database and already solves it with a variable — *"Two worktrees running this
config at once without `KBB_TEST_DB` drop the schema under each other mid-run and
report hundreds of failures belonging to neither of them."* This is the same
hazard in the other database, so it has been given the same answer: a
`geWpDb()` helper reading **`KBB_WP_DB`**, defaulting to `kbb_ge_wp`.

```bash
KBB_TEST_DB=kbb_gl KBB_WP_DB=kbb_gl_wp vendor/bin/pest -c phpunit-mysql.xml
```

The default is unchanged, so CI and anybody running one lane at a time see
exactly what they saw before. **The integrator may want this in CLAUDE.md's
Commands block beside `KBB_TEST_DB`**, because a lane that does not know about it
will hit the collision rather than the flag.

### 11.4 Nothing needed in `routes/web.php`, `bootstrap/app.php` or the admin app

This lane adds no route, no view and no Laravel class. **Lane GM owns the Laravel
side**, and what GM needs from this end is §2 and nothing else.

---

## 12. What only the owner can settle

1. **Whether the new shop should accept a zip at all, or only a folder.** Lane GM
   is building the first. If that lands, the whole journey is browser to browser
   and FTP never appears; if it does not, he unpacks each archive himself before
   pointing the import at it, which is one extra step per group and no risk.

2. **Whether an archive should be deleted after it is downloaded.** Today the
   screen tells him, in words, to delete the folder when he has them all. The
   alternative is the plugin deleting each archive as it is fetched, which is
   safer — the only completely safe copy is the one that is not there — and
   costs him the ability to download one twice, which on a connection that drops
   is a real cost. The claim that a download *completed* is a claim about a
   browser, and this lane is not willing to delete customer data on the strength
   of one.

3. **Whether the split cap is the right number.** 25 MiB of CSV, from a measured
   worst-case ratio of 35.6% and a target of ~10 MB per download. Nothing in his
   shop is near it and nothing splits. If his connection makes 10 MB unpleasant,
   the number moves and the splitter starts firing — that is a preference, not a
   correctness question.

4. **Whether `zip.of_export` should be acted on.** It records which groups the
   export produced; nothing reads it. The Import screen could say *"this archive
   is 1 of 3 from export 15402bdc; the other two are Catalogue and Customers"*
   above the run, which would put it in front of whoever presses Import rather
   than only in the file. That is a Laravel-side change in a lane that owns that
   screen.

5. **The real run.** Everything here was measured against WordPress-shaped MySQL
   tables with a `$wpdb` stub, and against synthesised CSVs at the real shop's
   row counts. `docs/GE-WP-EXPORTER.md` §9 lists what only the live site can
   answer; this lane adds one item to it — **the real compression ratio of his
   real product descriptions**, which §3.2 brackets with a sensitivity run rather
   than guesses.
