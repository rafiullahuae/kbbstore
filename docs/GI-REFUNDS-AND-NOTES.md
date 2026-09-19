# Refunds and order notes, imported · Lane GI

Lane GE's WordPress plugin writes `refunds.csv` and `order_notes.csv`. Nothing
opened them. This lane builds that side.

`docs/FV-IMPORT-AT-VOLUME.md` §11 names `refunds` first among the tables a
clean, complete, full-volume import leaves at zero, and states the cost in one
sentence:

> **Money the shop gave back.** `orders.status = refunded` imports, and the
> amount refunded does not. A partial refund imports as an order at its full
> total.

That is the sharp version, and it is sharper than "incomplete history": the
order history **overstates revenue**, silently, on every refunded order, in the
tables the owner reads to decide how his shop is doing — and it offers to give
the same money back a second time.

---

## 1. The headline, in figures

The export Lane GE's plugin really wrote (`tests/Fixtures/kbb-export/`), fed to
`App\Services\Import\ImportRunner` — the class `kbb:import` and Store → Import
both call, with no test double in the path.

**KBB-1003 (wc order 10235).** Paid AED 199.00 by card. WooCommerce refunded
AED 99.50 of it.

| | before this lane | after |
|---|---:|---:|
| `refunds` rows on the order | 0 | 1 |
| `PaymentRefunder::refundedFils()` | **0 fils** | **9,950 fils** |
| `refunded_total_aed` on the order screen | AED 0.00 | **AED 99.50** |
| still refundable through the Refund button | **AED 199.00** | **AED 99.50** |

The last row is the expensive one. The Refund button's ceiling is
`capturedFils() - refundedFils()`, and `refundedFils()` is
`SUM(refunds.amount)` over `PaymentRefunder::COUNTED`. With the table empty the
subtrahend was zero, so the panel offered the **whole AED 199.00** of an order
that already had AED 99.50 returned — a second refund through Stripe against a
charge that no longer holds it. It is now refused with `over_captured`, naming
AED 99.50 as what is left.

**KBB-1001 (wc order 10233).** Layla's, `completed`, AED 358.50, her only order.
AED 100.00 goes back. WooCommerce leaves the order `completed` because a partial
refund does not undo the sale, and this shop does the same.

| Store → Customers, Layla's lifetime value | before | after |
|---|---:|---:|
| `spend_fils` | **35,850** | **25,850** |
| displayed | AED 358.50 | **AED 258.50** |

358.50 − 100.00 = 258.50, to the fil, by integer arithmetic on the decimal
string. Both figures are read back out of `/admin-api/customers/list`, the same
endpoint the screen calls.

Every figure above is asserted in `tests/Feature/GiRefundsAndNotesTest.php`.

---

## 2. What the schema already had

This project has been wrong about "nothing exists" five times this month, so the
schema was read before anything was proposed. **Nothing was added. No migration,
no column, no index.**

| what | where it has been all along |
|---|---|
| `refunds.wc_refund_id` — nullable, **UNIQUE** | `0001_01_01_000000_create_kbb_schema.php`, Phase 0 |
| `order_notes.source_comment_id` — nullable, **UNIQUE** | the same file, the same commit |
| `refunds.status`, `.provider`, `.provider_ref`, `.failure_code`, `.idempotency_key` | `2026_09_17_000000_add_capture_and_refund_tracking.php` |
| `order_notes.author`, `.is_customer_note`, `.content` | Phase 0 |

`2026_09_22_000000_add_import_external_ids.php` names both external ids in its
own header as ids that were **already present** — it was written to add the five
that were missing, and neither of these was one of them.

So `refunds` was not a table waiting for a design. It was a table waiting for
rows, with the unique key that makes a re-run safe already on it. A test pins
both columns AND their uniqueness, because a repair migration re-adding a column
without its constraint is precisely how this repo has lost one before (see that
migration's header on `orders.wc_order_id`).

### What already read it, and why nothing downstream had to change

| reader | what it does with `refunds` |
|---|---|
| `AdminOrderController::show` | `refunded_total_aed`, `refundable_aed`, the refund list, `settlement.refundable_fils` |
| `PaymentRefunder::refundedFils()` / `capturedFils()` | the ceiling on the Refund button |
| `AdminController::stats` / `analytics` | revenue, netted through `countedRefunds()` |
| `CustomersApiController::rowQuery` | lifetime value, netted per order |
| `Reconciliation\Reconciler` | Q4, both directions |
| `Mail\OrderRefunded` / `OrderStatusChanged` | the partial-vs-full wording |

All of them were already correct and already waiting. `CustomersApiController`
even carries a comment anticipating this lane — *"an imported WooCommerce refund
never passed through that check, and one bad row must not be able to drag a
customer's lifetime value below what they actually paid"* — which is why an
over-total refund floors at zero rather than going negative.

**Writers before this lane: one.** `PaymentRefunder::refund()`, through
`PaymentLedger`. That is the whole of it.

---

## 3. The design decision: an imported refund vs. one this shop performed

A refund WooCommerce made and a refund this shop's Stripe integration made are
the same fact arriving by two routes. **They share `refunds`,** and they must:
every reader above wants both, and a second table would mean a second subtrahend
and a reconciliation that can never balance.

What separates them is **which system moved the money**, and the column that
says so is the one the schema already had:

| | imported | performed here |
|---|---|---|
| `wc_refund_id` | the WooCommerce refund id | **NULL** |
| `provider` | `woocommerce` | the order's gateway (`stripe`, `cod`, …) |
| `provider_ref` | NULL | the provider's own id |
| `idempotency_key` | NULL | the unique key that locks the call |
| `status` | `succeeded` | `pending` → `succeeded` / `failed` |

### Why `provider` is `woocommerce` and not `stripe`

This is the decision that would have cost the owner an afternoon a month.

`Reconciler::stepLocalRefunds()` selects `refunds WHERE provider = <gateway>` for
the gateway being reconciled, and reports every row whose `provider_ref` the
provider did not list:

> *"This shop has recorded a refund that stripe did not list for this period.
> The customer may not have been paid back."*

`refunds.csv` carries the WooCommerce refund **post id**. That is not a Stripe
`re_…` reference and never will be — the plugin cannot know it, because
WooCommerce does not store it in a column the export can reach. Filing an
imported refund under `stripe` would therefore raise a permanent false
`REFUND_NOT_CONFIRMED` on every one of them: the reconciliation that never
balances, exactly. Filing it under `stripe` with a NULL `provider_ref` would be
skipped by that phase but would still be a claim this shop's Stripe account
issued it.

`woocommerce` is not a `GatewayRegistry` id, so neither reconciliation phase
ever selects these rows. It is also simply true.

### Why `idempotency_key` is NULL

That column is a unique lock on **a call to a payment provider**. An import makes
no call. Inventing a key would put a value in a unique index that a genuine later
refund could collide with, for no benefit — and NULLs do not collide on either
engine, which is the property the capture/refund migration chose it for. The
re-run key is `wc_refund_id`, which is what that column is for.

### Why `status` is `succeeded`

`PaymentRefunder::COUNTED` is `['pending', 'succeeded']`, and COUNTED is what
every reader in §2 sums. A row imported as anything else sits in the table
looking imported and changes not one figure on any screen. This is the
load-bearing half of the mapping and it is mutation-tested twice.

### And the double-refund is prevented by the row existing

Nothing was added to `PaymentRefunder` to make this work. The imported row counts
against the ceiling because it is `succeeded`, so the panel cannot send the same
money twice. That is the correct shape: an imported refund is evidence money left
the shop, and the ceiling is built from evidence.

---

## 4. The customer is not emailed

**This was the one real hazard in the lane and it is not in the importer.**

`OrderMailObserver::register()` mails on `Refund::created` when the row arrives
already `succeeded` — which is exactly the shape of every row `RefundImporter`
writes. Left alone, importing a five-year refund history would tell several
hundred real people, **today**, that their money is on its way back. At the
volume FV measured that is **207 messages**.

`OrderStatusMailPolicy`'s import suppression does not cover it, and says so in
its own header: *"Refund mail is driven by the `refunds` row settling rather than
by a status column and never passes through here."*

So the guard is on the **row**, in `OrderMailObserver::mailRefund()`:

```php
if ($refund->wc_refund_id !== null) {
    return;
}
```

A refund that came from WooCommerce was announced by WooCommerce at the time.
Nothing mails about one — not this importer, not a delta re-run, not a row
touched by hand, not a later save that moves its status. A process-scoped
suppression would hold only while somebody remembered to wrap the call, and the
observer sends through `DB::afterCommit()`, where the wrapping is easy to get
wrong.

It is narrow, and that is asserted both ways round: a refund performed **here**
still mails. A guard that silenced a real refund would be worse than the one it
fixes.

`order_notes` needed no guard: writing one emails nobody, because the
customer-note email in this application is sent by the controller that creates
the note, not by the model.

---

## 5. Where the refund lines went

`docs/GE-WP-EXPORTER.md` excludes refund lines from `order_items.csv` —
WooCommerce keeps them in the same `woocommerce_order_items` table with
`order_id` pointing at the **refund** — and carries them here instead, on the
refund's own row, as `refunded_items` = `item_id:qty:total` pipes.

**They are not needed and they are not kept.** A refund in this shop is one
amount against an order. There is no `refund_items` table, and adding one would
be a second place for a line's money to live, which is how an order's lines and
its total start disagreeing. The **money is not lost** — it is `refunds.amount`,
in full, and that is what every figure in §1 is computed from. What is lost is
*which lines it covered*.

So it goes to the discard channel with its value, which is the channel Phase 13
built for exactly this: the owner approves a fact (`5506:-1:-99.50`) rather than
a column heading.

`OrderItemImporter`'s `max(0, $rawQuantity)` clamp and its ADJUSTED line stay
exactly as they are. It handles the other shape — an export that was **not** made
by Lane GE's plugin and does carry refund lines inline — and at volume that was
152 rows. The two behaviours do not overlap: a refund line reaching
`OrderItemImporter` is rejected by `order_id` before the clamp is reached when
the export is the plugin's, and clamped-and-named when it is somebody else's.

---

## 6. What the order screens now show that they did not

| screen | before | after |
|---|---|---|
| Store → Orders → an order | `Refunded` line absent; `Refundable` = the whole total | the refund listed with its amount, reason, date and `succeeded`; `Refunded AED 99.50`; `Refundable AED 99.50` |
| the same order's notes | only notes this shop wrote since go-live | WooCommerce's own history, in date order among them — `Order status changed from Processing to Completed.`, dated **2019-03-06**, internal |
| Store → Customers | lifetime value at the gross total | net of every refund, per order, floored at zero |
| Dashboard / Analytics | revenue gross | netted through `countedRefunds()` |
| the Refund button | offered money already returned | refuses with `over_captured` and names what is left |

An imported note carries its real date, so `Order::notes()` (which is `latest()`)
files it in the right place in the history rather than at the top.

`is_customer_note` defaults to **false** — internal. That is the safe direction
on a column whose other value is *show this to the buyer*: a WooCommerce internal
note routinely carries a courier's phone number, a chargeback reference or a
remark about the customer.

`author_email` is in the export and is **not** imported: `order_notes` has no
column for it, and one more address on a table an admin screen renders is one
more thing an endpoint can leak — CLAUDE.md records `reviews.author_email` and
`reviews.ip` as exactly that hazard. Named in the discard channel with its value.

---

## 7. The money rules

Integer fils throughout, parsed by `App\Services\Import\Money` out of the decimal
string. No float touches this path.

**`amount` and `total` are the same money written two ways.** Woo holds
`_refund_amount` positive and the refund order's `total` negative.
`docs/GE-WP-EXPORTER.md` emits both deliberately — *"two conventions, and an
importer guessing which it has applies a refund twice or backwards"*.

So this importer does not guess:

- both present and agreeing in magnitude → import it;
- both present and **disagreeing** → **refuse the row**, naming both figures.
  An ambiguous money value is the one thing this pipeline has never resolved by
  picking; `Money::resolveGrouping()` refuses `"99,50"` on the same argument;
- only one present → use it, `abs()`. `refunds.amount` is a magnitude and every
  reader subtracts it — a negative there would **add** to revenue;
- zero or empty → refuse. A refund of nothing is not a refund, and importing it
  would put a row on the order screen saying nothing went back.

**A refund bigger than the order's total is imported unchanged and named.** Not
clamped, not refused. WooCommerce's record is the evidence money moved;
`orders.total` is a column an operator can edit, and
`PaymentRefunder::capturedFils()`'s header sets out what trusting it as a ceiling
has already cost this shop **in both directions**. The report names the order and
both figures. The screen floors at zero rather than going negative.

**A refund in a currency that is not the order's** is imported and named.
`refunds` has no currency column (the `Reconciler` relies on that), so the figure
is netted out of a total denominated in something else. Not converted: there is
no rate for the day it happened, and inventing one is worse than naming it — the
same argument `OrderImporter` gives about foreign-currency orders.

**`refunded_by` is spelled out.** Woo names the refunder by WordPress user id.
That column is printed beside the money on the order screen, where a bare `1` is
not a person, so it lands as `WordPress user 1` and the change is reported.

---

## 8. Registration, and the two lists that have to agree

Two hand-maintained lists. Registering `seo` on one and not the other made every
upload on Store → Import answer 500 instead of 422, and cost a package.

`ImportRunner::entities()` — **inserts** after `new OrderItemImporter,` (it does
not append; `seo` stays last, which its own comment says is a dependency):

```
… categories, brands, products, coupons, customers, orders, order-items,
  refunds, order-notes, reviews, seo
```

`ImportWorkspace::ENTITIES` — inserted at the matching position, keyed
`refunds` and `order-notes`. `AdminImportScreenTest` pins the whole walk and
`GiRefundsAndNotesTest` pins that the two lists are identical.

**After orders** is a dependency, not a preference: both attach by `wc_order_id`
through the run's id map, and `refunds.order_id` and `order_notes.order_id` are
both NOT NULL, so an orphan is refused rather than half-written. Not dependent on
`order-items`; placed after it only so the order-shaped entities stay together on
a screen that steps **one entity per request**, which they do.

`ImportDriver`'s one-entity-per-step property is untouched — it walks
`ImportRunner::entityNames()` and never skips forward, so two more names is two
more steps and nothing else.

### The exact registration, for the integrator

**`app/Services/Import/ImportRunner.php` — use statements. INSERT.** Anchor
(occurs once):

```php
use App\Services\Import\Entities\OrderItemImporter;
```

Replace with:

```php
use App\Services\Import\Entities\OrderItemImporter;
use App\Services\Import\Entities\OrderNoteImporter;
use App\Services\Import\Entities\RefundImporter;
```

**`app/Services/Import/ImportRunner.php` — `entities()`. INSERT, not append.**
Anchor (occurs once, inside `entities()`):

```php
            new OrderItemImporter,
```

Replace with the anchor plus the two new lines and their comment (the full text
is in the file on `lane/import-refunds-and-notes`; the operative lines are):

```php
            new OrderItemImporter,
            /* … see the branch for the dependency note … */
            new RefundImporter,
            new OrderNoteImporter,
```

**Verified by count:** `ImportRunner::entities()` goes from **9** entries to
**11**; `ImportRunner::entityNames()` from 9 to 11; `ImportWorkspace::entities()`
likewise, and the two must be `===`. Lanes GH and GJ register their own entities
this round — neither of their anchors is `new OrderItemImporter,`, so the three
inserts do not collide, but the **count after all three are applied is 9 + one
per lane's entities**, not 11.

No route is added, so no `clear_caches_*` migration is needed for this lane on
that account. No migration at all is added.

---

## 9. Mutation testing

Every guard broken, the suite run, the guard restored. **Money guards twice**, as
the brief requires — eight money mutations in total.

MUTATION_TABLE_PLACEHOLDER

---

## 10. Test evidence

| | |
|---|---|
| new | `tests/Feature/GiRefundsAndNotesTest.php` — 19 tests |
| extended | `tests/Feature/GeWpExporterTest.php` — the round trip now closes on the refund and the note |
| new fixtures | `tests/Fixtures/woo/refunds.csv`, `tests/Fixtures/woo/order_notes.csv` (defect-bearing, in the style of the rest of that folder) |
| moved | the unread-file channel in `ImportDryRunTest`, `ImportAtVolumeTest` and `GfImportRefinementTest` now pins on `variations.csv` and `tags.csv` |

The count-verification contract is satisfied: `countImported()` counts on the
external id (`wc_refund_id`, `source_comment_id`) and not the whole table,
because this shop writes its own refunds and its own notes and `COUNT(*)` would
answer a different question. Both agree with `manifest.json`'s `counts`, both
report `unaccountedCount() === 0`, and a second pass reports `created: 0,
updated: 0` with `unchanged > 0` — the importer's own dirty check, which is the
only evidence of idempotency it cannot fake.

### The unread-file channel moved, and that is the point

`refunds.csv` and `order_notes.csv` were the two files three separate tests used
to prove *"a file sat in the folder and nothing said it was never opened"*. They
are entities now, so those tests would have been proving the channel works using
files it must no longer name. Each was moved to `variations.csv` / `tags.csv`,
which really are unread, **and** each now asserts the opposite for the four files
that are imported — a channel that still named `refunds.csv` would be telling the
owner his refunds were dropped on a run that imported them.

`tools/woo-volume-fixture/generate.php` still writes `unread.refunds.csv` and
`unread.order_notes.csv` into its manifest. That key is the generator's own
bookkeeping and is left alone; what matters is which files the **report** names,
which is what `ImportAtVolumeTest` now asserts in both directions.

---

## 11. Only the owner can settle these

1. **Refund lines are not kept.** §5. The money is exact; which lines it covered
   is not recorded. If the owner wants a refund's breakdown he needs a
   `refund_items` table, and that is a decision about the data model, not a
   mapping that was skipped. At volume it is 207 refunds' worth of breakdown.

2. **A WooCommerce-era refund cannot be reconciled against the provider.** The
   export carries no `re_…` reference, so §3's `provider = woocommerce` means
   these rows are invisible to Store → Payments' reconciliation for ever. That is
   correct — there is nothing to reconcile them against — but it means a refund
   Stripe made in 2023 will show as `REFUND_NOT_RECORDED` if the owner ever runs
   a reconcile window reaching back before the migration. Worth knowing before he
   does.

3. **`refunds` has no currency column.** §7. A refund of a USD order is netted
   out of an AED total. The same is already true of `orders.total` (see
   `OrderImporter`'s foreign-currency note), so this is not new — but the refund
   half of it is now live where before the table was empty.

4. **Refunded orders whose WooCommerce status is `refunded` are outside
   `Order::REAL_STATUSES` either way.** The netting in §1 matters for the
   **partial** case, where the order stays `completed`. A shop that marks
   everything `refunded` in Woo sees less change than this document implies; a
   shop that does partial refunds properly sees all of it. FV measured 520 orders
   in `refunded` at volume and did not break them down by how much came back.

5. **`refunded_by` becomes `WordPress user 1`, not a name.** `users.wp_user_id`
   exists and the owner intends to bring WordPress users across; once they are
   here, that string could be resolved to a name. It is not, this round, because
   an importer that silently resolved it would produce a different value
   depending on whether users had been imported yet.
