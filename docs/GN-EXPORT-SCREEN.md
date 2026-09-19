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
