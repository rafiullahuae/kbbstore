# GN — "why you mentioned number of rows to select?"

The shop owner, looking at the Run row of **Tools → KBB Export**, asked:

> *why you mentioned number of rows to select? what's the purpose?*

He was right to ask, and this lane is the answer: the field was plumbing standing
in the main flow, so it moved behind a disclosure. Nothing about how it works
changed.

---

## 1. Why it was the wrong thing to ask him

`Rows per batch` exists for one reason. The browser is the scheduler for this
export — it POSTs `kbb_export_step`, the server does one batch, the page POSTs
again — and the batch size is what keeps a single request from outliving a shared
host's PHP time limit.

That is a real constraint. What is not real is the idea that he has a view on it.
`docs/GL-GROUP-DOWNLOADS.md` §5 measured the actual shop — 671 products, 4,159
orders, 10,571 line items, 3,712 customers — and found:

> **Whole folder 15.85 MB. Largest single download 2.16 MB. 25 bounded units,
> slowest one 293 ms.**

293 ms at the default 200, against a limit that is typically 30 seconds. That is
two orders of magnitude of headroom. There is no number he could pick that would
be better than the one already there, and no symptom he could observe that would
tell him to pick a different one — the export simply finishes.

So the field was asking him for an opinion he cannot form. Worse, it was asking
it *in the Run row*, one line above **Start a new export**, which reads as "decide
this before you press the button". The question he asked is what that looks like
from his side.

## 2. Why it stays anyway

It is a genuine escape hatch. The 293 ms is this shop on this host; a host with a
stricter limit is the case the batching exists for at all, and on that host
lowering the number is the fix. Deleting the control would be trading a question
he cannot answer for a wall he cannot climb.

So it **moved**, and moved with everything about it intact:

- still posted with every request, from the same `post()` line, unchanged;
- still clamped to 10–1000 on the server, and still defaulting to 200;
- still `min=10 max=1000 step=10 value=200` on the input itself.

## 3. What it moved into

A plain `<details>`. No JavaScript, no CSS, no dependency, no build step — the
constraint is that the plugin installs by zip upload, and a `<details>` is the
cheapest thing that is also correct.

```
Run
  [ ] Include trashed products and unpublished coupons (…)
  [Start a new export]  [Resume]  [Pause]
  ▸ Only if the export keeps stopping before it finishes
```

Opened, it reads:

> The export is written in small pieces rather than all at once, so that no single
> piece takes long enough for your web host to cut it off part way. **Rows per
> batch** is how many rows go into one piece.
>
> **Leave this alone.** On a shop this size the slowest piece takes about a third
> of a second, and hosts normally allow thirty — so there is nothing to gain by
> changing it. The one time it helps is a host that is stricter than that: if the
> export keeps stopping on its own, make this number smaller and press
> **Resume**. It carries on from where it got to, and nothing already written is
> lost or done twice.

Three deliberate choices in that wording:

- **The summary names the symptom, not the setting.** "Only if the export keeps
  stopping before it finishes" is a thing he can observe. "Rows per batch" is not
  — it is the word he already told us means nothing to him. A test asserts the
  summary does *not* contain "batch".
- **"Leave this alone" is stated, not implied.** The brief for this screen is that
  he should not have to form an opinion; the honest way to deliver that is to say
  so rather than to hide the field and hope.
- **It names Resume.** That is the whole procedure, and it is the half that is
  easy to leave out: the value only helps if he knows what to press after
  changing it.

### Why it sits *after* the buttons

It is only ever the answer to an export that has already stopped. Somebody
reaching for it has pressed Start and watched it fail, and the way out from there
is **Resume** — the button it is now directly beneath. Above the buttons it would
be back in the pre-flight flow, which is the thing being fixed.

## 4. Screenshots

| | |
|---|---|
| `docs/gn-screen-shots/01-at-rest-collapsed.png` | As he finds it. The Run row is one tick, three buttons, and a one-line summary. |
| `docs/gn-screen-shots/02-disclosure-opened.png` | Opened, with the explanation and the input. |

Both are written by the browser run in `tests/Feature/GnExportScreenTest.php`,
so they are the real screen and not a mock-up.

---

## 5. How it is tested, and why that took a browser

Everything on this screen is markup and JavaScript, and the PHP suite cannot see
a line of it. That is not a worry, it is a **measurement**: `docs/GK-EXPORT-GROUPS.md`
§6.1 records that deleting `body.set('groups', …)` from this page — so that every
export became a whole export whatever was ticked — left the entire suite green.

So this lane's claims are checked in two halves.

### The server half — `it still hands the batch size to the runner unchanged…`

Runs everywhere, no browser and no MySQL. It calls
`KBB_Export_Admin::settings_from_request()` in a **subprocess** (the class is
WordPress code — `defined('ABSPATH') || exit` at the top, `add_action()`
callbacks below — and requiring it into the Laravel test process would put a
second definition of the plugin's classes beside whatever the harness scripts
loaded). It asserts both bounds *at* the boundary and *outside* it, that 50 (a
lowered value, which is the escape hatch actually being used) arrives untouched,
and that a request carrying no batch at all still gets 200 — which is not
hypothetical, it is what a tab opened before this change posts.

### The screen half — `it moves the batch setting behind a disclosure…`

`wordpress-plugin/harness/screen.php` renders the page from
`KBB_Export_Admin::screen()` itself, and a new `--plumbing` mode in
`wordpress-plugin/harness/screen-drive.mjs` drives it in Chromium:

1. the disclosure is present and **shut**, and the input is really *inside* it;
2. shut means **not visible**;
3. an export started **without the disclosure ever being opened** still posts
   `batch=200` on every request;
4. opened, the input is revealed and a changed value (`50`) reaches every
   subsequent request.

Claim 3 is the load-bearing one. A `<details>` keeps its contents in the DOM — that
is precisely why it is the cheap correct answer here — so the value is still
readable by `post()` when nothing has been opened. A change that silently stopped
sending the field would look *identical* on screen and would produce a 200-row
batch by accident rather than by design.

The trashed checkbox is asserted the other way round: it must **not** be inside
the disclosure and it must be visible at rest, so a change that swept the whole
Run row out of sight fails here instead of passing.

Two more guards on the markup, before the browser sees it: exactly **one**
`id="kbb-batch"` and exactly **one** `id="kbb-batch-details"`. "Moved" and
"copied" are the same page to a driver that only ever finds the first match — a
second input left behind in the Run row would satisfy every browser assertion
above while he still stared at the field he asked about.

### The probe that reported the opposite of the truth

The obvious way to ask "is it visible" is `getClientRects().length > 0` or
`offsetParent !== null`. **Both are wrong here**, and both say *visible* while the
disclosure is shut.

This Chromium closes a `<details>` with `content-visibility: hidden` on the slot
rather than `display: none`. The input therefore still has a layout box: client
rects is 1, `offsetParent` is non-null, computed `display` is `inline-block`.
`checkVisibility()` understands `content-visibility` and answers `false`, and
Playwright's own `isVisible()` agrees by a different route. Both are recorded and
both are asserted — a single wrong probe would have passed the assertion
vacuously, which is the same shape of hole as everything else on this page.

## 6. Mutation testing

Thirteen mutations, each applied, run, and restored. **All thirteen went red.
None survived.**

| # | Mutation | Caught by |
|---|---|---|
| M1 | `<details open …>` — disclosure starts open | `details_open_at_rest` |
| M2 | Input moved back out into the Run row (still exactly one) | `batch_inside_details`, `batch_visible_at_rest` |
| M3 | `body.set('batch', …)` deleted from `post()` | `batches_never_opened` |
| M4 | Trashed **decision** swept behind the disclosure too | `trashed_inside_details` |
| M5 | Server clamp `max(10, min(1000, …))` removed | server half, both bounds |
| M6 | Server default 200 → 500 | server half, no-batch case |
| M7 | Input **copied** rather than moved (one left in the Run row) | `substr_count(id="kbb-batch") === 1` |
| M8 | `min`/`max` stripped off the input | `batch_min`, `batch_max` |
| M9 | Summary reverts to naming the plumbing ("Rows per batch") | summary wording |
| M10 | Visibility probe weakened to `getClientRects()` | Playwright second opinion |
| M11 | `<details>` replaced by a plain `<div>` | `details_present` |
| M12 | `post()` hardcodes `'200'` — escape hatch silently dead | `batches_after_change` |
| M13 | A screenshot goes missing | screenshot guard |

M10 is worth the row it takes: it confirms the second opinion is load-bearing
rather than decoration. With the probe weakened to the mechanism that lies, the
test still fails — on Playwright's reading alone.

---

## 7. What else on this screen asks him for an opinion he cannot form

The brief was to change only plumbing and to **report** anything else. Three
things, none of them touched.

### 7.1 `declared=no wc_orders=present-but-not-authoritative`

Under **Where this shop keeps its orders**, the second line is the detection
detail, and on this fixture it reads:

> `declared=no wc_orders=present-but-not-authoritative`

This is a developer diagnostic under a heading phrased as a question about his
shop. He cannot evaluate "present-but-not-authoritative", and the section offers
no action, so it reads as something he ought to check and form a view on.

It is **not** plumbing in the sense the batch field was — it is a readout, and it
earns its place: when detection fails, this section carries the error and **Start
is disabled**. The headline sentence above it ("orders are `shop_order` posts and
their fields are `wp_postmeta` keys") is doing real work.

*Recommendation, not done:* keep the headline, and put the `declared=… wc_orders=…`
string behind the same kind of disclosure with a summary naming who it is for —
"details for whoever helps you with the site". That is a judgement call about
somebody else's section rather than plumbing, so it is written here instead.

### 7.2 The screen tells him to delete a folder he cannot reach

The intro says, correctly and importantly:

> When you have downloaded them all, **delete the folder from the server** —
> `customers.csv` holds every shopper's address and password hash and
> `reviews.csv` holds reviewers' email addresses and IPs.

He has **no shell and no FTP**. That is not incidental — it is the premise of this
whole design, and the paragraph two lines above it says so in its own words: *"no
FTP, and no single heavy file"*. So the one instruction on this page that protects
every shopper's password hash is the one instruction he cannot carry out.

It is worse than a missing button. `wp_ajax_kbb_export_reset` **is registered**
(`class-kbb-export-admin.php:57`) and `ajax_reset()` is implemented — but nothing
on the screen ever calls it, and `KBB_Export_Runner::reset()` only does
`delete_option( self::STATE_OPTION )`. It forgets the export; it does not delete
a single file. Wiring the existing endpoint to a button would therefore be
actively dangerous: it would report success while `customers.csv` stayed on disk,
now unreferenced and un-downloadable, with the screen no longer able even to name
what it left behind.

*Recommendation, not done:* a **"Delete the export from the server"** button that
really removes `uploads/kbb-export/<id>/` and its contents, shown once every group
is downloaded, with the archives' names listed so he can see what is going. This
is a new feature with a destructive path in it, not a plumbing move, so it is
reported rather than built.

### 7.3 Small things, deliberately left

- **`--` where the page uses an em dash.** The "Addresses and pictures" help text
  in `class-kbb-export-groups.php` contains `itself -- the image LINKS`, while the
  rest of the page uses `—`. Cosmetic, in Lane GK's file, and the brief says do
  not restyle.
- **The trashed tick's parenthetical** — *"leave this off unless you know why you
  want them"* — is the same shape of sentence as the new disclosure's "Leave this
  alone", and correctly so: this one **is** a decision, he can form it (he knows
  whether his trash holds anything he wants), and the default is right. Left
  exactly as it was.

## 8. What was deliberately not touched

- **Lane GK's dependency warnings** and the "already imported into the new shop"
  confirmations. The confirmation asks him about the *other* shop, which is a
  question he can answer.
- **Lane GL's per-group downloads** and the three download states.
- **The progress bars**, overall and per group.
- **The group list, its order, its names and its help text.**
- **No restyling.** The disclosure uses the same inline-style idiom already
  throughout the file and WordPress's own `.description` / `.button` classes.

## 9. One correction to the brief

The brief asked that the batch value "still be **pinned at `start()`** the way
`skip_trashed` and `groups` are". **It is not pinned today, and it must not be.**
It is deliberately the one exception, and the code says so at
`class-kbb-export-runner.php` `start()`:

> Whatever was chosen when Start was pressed is what the whole export runs under;
> `batch` is the one exception, because how many rows fit in a request is a
> property of the host and not of the data.

`pinned_settings()` re-takes `batch` from the current request on purpose, and the
existing test `it pins its settings to the export, not to the request that asked
for a batch` pins exactly that division.

The reasoning holds. `skip_trashed` changes the `WHERE` — flip it mid-run and one
file holds the first two thousand products under one rule and the rest under
another, with nothing in it to say where the line is. `groups` changes the stage
list that `state['stage']` indexes. `batch` changes neither: it is the `LIMIT`
size on an ordered cursor scan, so the same rows are selected, in the same order,
from the same cursor — only in differently sized bites.

And pinning it would **break the escape hatch this lane just documented**. The
only usable procedure for a stricter host is *lower the number and press Resume*.
Pinned at `start()`, `step()` would keep reading the value the export began with,
and the fix would be to abandon a part-finished export and start again from zero —
on the one host where finishing is already the problem.

Left as it is, and no test was written asserting the opposite.

---

# 10. The export can now be deleted from the browser (Lane A, Phase 13 item 4)

## 10.1 The defect was two defects on top of each other

The screen has always said, correctly:

> When you have downloaded them all, **delete the folder from the server** —
> `customers.csv` holds every shopper's address and password hash and
> `reviews.csv` holds reviewers' email addresses and IPs.

**The owner has no shell and no FTP**, and the paragraph two lines above that one
says so itself. There was no control that did it.

**And the obvious fix was a trap.** `wp_ajax_kbb_export_reset` is registered and
reachable. Wired to a button labelled Delete it would answer `ok: true`, the bar
would go back to zero, and the screen would look exactly as it does after a real
delete — because `KBB_Export_Runner::reset()` calls `delete_option()` and nothing
else. The hashes stay on disk, now with nothing in the admin referring to them
and no download link left to reach them: **worse than before**, because the owner
has been told they are gone.

That trap is pinned as a test — `it proves that the endpoint which already
existed deletes nothing` — which calls `reset()` and then asserts every file is
still on disk. It passes before and after this change, deliberately: it is not
asserting a fix, it is recording why the fix could not be a wire-up.

## 10.2 What was built

`KBB_Export_Runner::purge( $confirmation )`, behind
`KBB_Export_Admin::ajax_purge()`, with three things in front of it and no one of
them sufficient alone.

**1. A capability of its own, narrower than the screen's.**
`manage_woocommerce` is right for *running and downloading* an export — it is
what a shop manager has. It is wrong for *destroying* one: the export is the only
copy of a several-minute run, and on cutover day the only copy of the shop's data
that is not on the site being switched off.

```php
const DELETE_CAPABILITY          = 'kbb_export_delete';
const DELETE_FALLBACK_CAPABILITY = 'delete_users';   // administrator-only in core
```

A site can grant `kbb_export_delete` to exactly one person and nothing else. The
fallback is `delete_users` and deliberately not `manage_options`: a shop manager
does not have `delete_users`, which is the line being drawn. It fails closed —
403 from the endpoint, not a hidden button, because `add_management_page()`
decides what is in a menu and not what answers a URL.

**2. Its own nonce**, `kbb_export_purge`. `DOWNLOAD_NONCE`'s reasoning one step
further: a leaked or replayed body that could *start* an export is a nuisance;
one that could *delete* one is the incident.

**3. A typed confirmation**, `DELETE`, compared case-sensitively **on the
server**. The page's disabled button is a courtesy to the person using it; the
endpoint is reachable without the page.

## 10.3 The answer is a re-scan, not a counter

`ok` is true when a **fresh walk of the folder finds no file left**, and
`remaining` names what is still there when it is not. A count of successful
`unlink()` calls would report success for a folder half of which could not be
removed — which is the exact failure this whole feature exists to stop reporting.
On shared hosting the thing that actually happens is a file owned by a different
UID than PHP runs as, and the owner has to be told its name.

## 10.4 It cannot be steered out of the folder

* The root is **computed** (`exports_root()` = uploads base + a literal). Nothing
  a browser sends is concatenated into a path, so there is no traversal to
  sanitise because there is no path from the request.
* Every entry's `realpath()` must still be under the root's `realpath()`, with a
  separator appended to both sides so `/uploads/kbb-export-old` cannot pass as
  inside `/uploads/kbb-export`.
* **A symlink is unlinked and never followed.** `is_link()` is tested *before*
  `is_dir()`, because a symlink to a directory answers true to both — and
  recursing through one turns a delete inside uploads into a delete of
  `wp-config.php`.
* Depth is bounded (`PURGE_MAX_DEPTH = 8`).

**The folder's guards survive.** `kbb-export/index.php` and `kbb-export/.htaccess`
hold nothing and are what stop the folder being listed or served. Removing them
to tidy up would open a window if anything recreated the folder before
`write_index_guard()` next ran.

## 10.5 What the screen shows, and what it is read from

*Tools → KBB Export*, a new section at the bottom: **"Delete the export from this
server"**. The table is read off the **disk**, not out of the state option — the
option knows about the export this plugin is part-way through, and the disk knows
about the four from last week that were downloaded and left there, which are the
ones this section exists for. Each row names the sensitive files rather than
counting them: "12 files" is not a reason to press a destructive button and
"customers.csv, which holds every shopper's address and password hash" is.

The paragraph at the top of the screen now ends *"There is a button for it at the
bottom of this page … you do not need FTP or a shell."*

## 10.6 Driven in Chromium, before and after, against real files

Two export folders, 14 files, 5.0 MB, served by a stand-in WordPress
(`KBB_Export_Admin::screen()` and `admin-ajax.php` are the real plugin code; only
WordPress's own functions are stubbed). Measured, not asserted:

| | 390 px | 1280 px |
|---|---|---|
| `document.documentElement.scrollWidth` | 390 | 1280 |
| `clientWidth` | 390 | 1280 |
| horizontal overflow | none | none |
| exports listed before | 2 | 2 |
| button state with the box empty | disabled | disabled |
| button state after typing `delete` | **disabled** | **disabled** |
| button state after typing `DELETE` | enabled | enabled |

Then the button was pressed at 1280:

```
files on disk before                     14
customers.csv present before             yes

files on disk after                       2   (index.php and .htaccess)
customers.csv present after              no
folder guards kept                       yes / yes
exports listed after                      0
what the screen says                     "Deleted 14 item(s), 5.0 MB freed.
                                          Every export file is gone from this
                                          server. The folder guards (index.php
                                          and .htaccess) were left in place;
                                          they hold nothing."
```

## 10.7 The tests, and the mutations that prove they assert something

`tests/Feature/GnExportPurgeTest.php`, 8 tests. The plugin is WordPress code, so
each call runs in a **separate PHP process** with WordPress reduced to the
functions it uses — the same shape `GnExportScreenTest`'s settings probe uses,
and for the same reason. No MySQL is needed: everything this feature does happens
on a filesystem, which is real.

**Every assertion about deletion is `is_file()`, never a status code.** The brief
is explicit that a test asserting the endpoint answered 200 asserts the bug.

| Mutation | Goes red |
|---|---|
| remove the `self::PURGE_PHRASE !== $typed` branch from `purge()` | `it deletes nothing without the typed confirmation` |
| move the `is_link()` branch below the `is_dir()` branch in `remove()` | `it unlinks a symlink instead of following it out of the uploads folder` — the file outside the folder disappears |
| `can_delete()` returns `current_user_can( self::CAPABILITY )` | `it fails closed for a user who can run the export but not destroy it` |

## 10.8 One behaviour change to be aware of

`measure()` no longer decides which files are guards from its recursion depth; it
is told. An export folder carries an `index.php` of its own, and measuring one of
those from `exports()` also starts at depth 0 — so the depth test quietly
under-counted every export by one file while looking exactly right.

## 10.9 A second pass over the same delete — and one thing it was still doing wrong

The Phase 13 entry was re-read against the code rather than taken on trust, and
**four of its five claims were already false** by the time it was read. What is
true today, checked line by line:

| The entry said | The code says |
|---|---|
| "There is no control that does it" | There is: **Tools → KBB Export → Delete the export from this server** |
| "`wp_ajax_kbb_export_reset` is registered and reachable" | True, and still registered — it is `ajax_start()`'s way of beginning at row one |
| "`KBB_Export_Runner::reset()` only `delete_option()`s the state" | True, and its docblock now says so in the first line so nobody wires it to a button |
| "wiring the existing endpoint to a button would report success while the hashes stayed on disk" | True, and pinned as a test that calls `reset()` and then asserts the files are **still there** |
| "Wants a real delete" | `KBB_Export_Runner::purge()`, behind `ajax_purge()`, capability + nonce + typed word |

**And the export folder is already unreachable over HTTP.** `write_index_guard()`
writes three things on the first batch of every export and they are all still
there: an `index.php` in the export folder, an `index.php` in `kbb-export/`, and
a `kbb-export/.htaccess` carrying `Require all denied` for `mod_authz_core` with
an `Order allow,deny` fallback for older Apache. The export id in the path is
random on top of that. The delete leaves all three in place on purpose.

**What was still wrong is the one thing this feature exists to prevent.**
`purge()` computed `ok` from a fresh walk — right — but the walk carried the same
`PURGE_MAX_DEPTH` ceiling as the delete and called the same `scandir()`, and in
both of the places where it gave up it returned **zeros**. Zeros read as "there
is nothing there". So:

```
ok        true
remaining []
note      "Every export file is gone from this server."
```

…with `customers.csv` and its WordPress password hashes still on the disk and
the export folder still standing, because `remove()` had stopped at the same
ceiling and every `rmdir()` on the way back up failed on a non-empty directory.
Reproduced against real files before it was fixed; the reproduction is now
`it does not answer ok for a folder it could not finish reading`.

The fix is **not a deeper walk** — a recursive delete with no ceiling is a stack
overflow away from a half-deleted folder. `measure()` returns `blind`: the
folders it had to give up on, named by their path under the export root, for the
two reasons that also stop `remove()` (past the ceiling, and a `scandir()` that
answers `false` — the shared-hosting folder owned by another UID). `purge()`
merges them into `remaining` and `ok` is false while any of them exists. **A
walk that cannot see must say so**, or it is a counter again with a longer walk
in front of it.

## 10.10 The pictures, taken against real files

`wordpress-plugin/harness/purge-serve.php` serves the plugin's **own**
`KBB_Export_Admin::screen()` and its **own** `ajax_purge()` over HTTP against a
real folder, and `purge-drive.mjs` drives it in Chromium.

It is deliberately not `screen.php` + `screen-drive.mjs`. That pair intercepts
`fetch()` and answers admin-ajax itself, which is right for putting the export
into "mid-run" and "stalled" — and **wrong here, in the exact direction this
feature guards against**. A faked endpoint answering `{"ok":true}` draws the same
screen whether the files went or stayed, which is the `wp_ajax_kbb_export_reset`
trap rebuilt inside the harness. Every number below is read from `/state`, which
walks the folder with `RecursiveDirectoryIterator`, not from the page and not
from the endpoint's reply.

```bash
KBB_PURGE_UPLOADS=/tmp/kbb-purge-shots \
  php -S 127.0.0.1:8731 wordpress-plugin/harness/purge-serve.php &
node wordpress-plugin/harness/purge-drive.mjs \
    --base=http://127.0.0.1:8731 --shots=docs/gn-purge-shots
```

No MySQL, on purpose: everything the delete touches is a filesystem, the only
thing on the page that reads the shop is `KBB_Export_Orders_Source::detect()`'s
`SHOW TABLES LIKE`, and a harness that can only take its pictures while a
database happens to be up is a harness whose pictures stop being taken.

Two exports, 16 files, 1.7 MB. `docs/gn-purge-shots/`.

| | 390 px | 1280 px |
|---|---|---|
| `document.documentElement.scrollWidth` | 390 | 1280 |
| `clientWidth` | 390 | 1280 |
| horizontal overflow | 0 | 0 |
| exports listed before | 2 | 2 |
| what the row names | `customers.csv, reviews.csv, orders.csv` | same |
| button with the box empty | disabled | disabled |
| button after typing `delete` | **disabled** | **disabled** |
| button after typing `DELETE` | enabled | enabled |
| files on disk before | 16 | 16 |
| files on disk after | **2** (`index.php`, `.htaccess`) | **2** |
| `customers.csv` after | **gone** | **gone** |
| media library and `kbb-export-old/` after | untouched | untouched |
| exports listed after | 0 | 0 |

What the screen says afterwards, at both widths:

> Deleted 16 item(s), 1.7 MB freed. Every export file is gone from this server.
> The folder guards (index.php and .htaccess) were left in place; they hold
> nothing.

And the shop manager (`?caps=manage_woocommerce`), 1280 px: **no button at all**,
the plugin's own sentence in its place — *"Deleting the export needs the
`kbb_export_delete` capability, which an administrator has and a shop manager
does not."* — and 16 files still on disk.

| shot | what it is |
|---|---|
| `390-1-before.png`, `1280-1-before.png` | the section, two exports listed, button disabled |
| `390-2-confirm-lowercase.png`, `1280-2-…` | `delete` typed — still disabled |
| `390-3-confirm-armed.png`, `1280-3-…` | `DELETE` typed — enabled |
| `390-4-after.png`, `1280-4-after.png` | pressed: empty list, and the count freed |
| `1280-5-shop-manager-refused.png` | the capability failing closed on the page |

## 10.11 The mutations, run rather than reasoned about

`tests/Feature/GnExportPurgeTest.php`, **11 tests**. Each mutation below was
applied to the working tree, the file was run, and the tree was restored.

| Mutation | Result |
|---|---|
| fold the depth ceiling back into `measure()`'s first guard — `if ( $depth > self::PURGE_MAX_DEPTH \|\| ! is_dir( $path ) …`| 1 failed, 10 passed — *it does not answer ok for a folder it could not finish reading* |
| `exports_root()` returns `KBB_Export_Wp::uploads_dir()`, one segment wider | 5 failed, 6 passed — including *it leaves everything outside the export folder alone*, which loses the media library |
| `remove()`'s confinement test drops the separator: `strpos( $real, rtrim( $root, '/' ) )` | 1 failed, 10 passed — *it refuses a path that only looks like it is inside the export folder* |
| move the `is_link()` branch below the `is_dir()` branch in `remove()` | 1 failed, 10 passed — *it unlinks a symlink instead of following it out of the uploads folder* |
