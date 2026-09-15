# Import readiness

What a WooCommerce importer has to know about this database before it writes a
single row: which tables receive imported data, what identifies an imported row
as the same row next time, what the database will refuse, and which columns have
to be set for the application to behave correctly afterwards.

Everything here was checked against the migrations and models in this repository
rather than inherited from an earlier survey. Where the earlier survey was
wrong, it says so.

The premise throughout is that **the import is not one run**. It is a full
import, a delta, a cutover delta on the night, and however many re-runs it takes
to get a mapping right. Every one of those passes re-presents rows the previous
pass already inserted. "Runs twice" is the normal case, not an edge case, and
almost everything below follows from it.

---

## 1. Decisions the owner has to make before the import runs

These cannot be settled in code. Each one is explained in full further down.

| # | Decision | Why it cannot wait |
|---|---|---|
| D1 | What to do with a Woo order that has **no email address**. | `customers.email` is `UNIQUE NOT NULL`. A blank one cannot be inserted, so the importer must either synthesise a placeholder or skip the customer and leave the order unlinked. |
| D2 | What to do when **two Woo users share one email**. | Same constraint. One of them cannot become a customer row. Merging them is usually right, but merging is lossy and is the owner's call, not the importer's. |
| D3 | Whether guest orders should get **synthetic customer rows**. | Guest orders import with `customer_id` NULL and then sit outside every per-customer figure. Creating a customer row per guest email fixes that and changes what "customer count" means. |
| D4 | Whether **WordPress admin users** come across at all. | `users` has no `legacy_password` column, unlike `customers`. WP admin password hashes cannot be carried; every imported admin must reset. |
| D5 | Whether `orders.order_number` should be the **Woo order id or the Woo order number**. | The column is `UNIQUE`. Woo stores with sequential-number plugins have two different values and only one can go in. |
| D6 | Whether historical `invoice_number` values come across. | `UNIQUE`, and the WebToffee sequence is meant to continue from `MAX(wf_invoice_number)`. Importing some but not all leaves a gap the next invoice may collide with. |

---

## 2. The five reported gaps, verified

### 2.1 `orders_count`, `total_spent`, `last_order_at` — **CONFIRMED**

`customers` carries all three. Nothing in the application writes any of them.
The only references anywhere are the Phase 0 schema, the repair migration that
re-adds them, and two casts on the `Customer` model. On every install they read
`0`, `0` and `NULL` for every customer, including one with a long order history.

`CustomersApiController` deliberately ignores them and aggregates from `orders`
at query time, using `Order::REAL_STATUSES` so a cancelled order is not counted
as spend. That is correct, and it is why the Customers screen shows real numbers.

**Recommendation, and what this lane implemented: retire them in place. The
importer must not write them.**

Not maintained, because a lifetime total is a derived value with five separate
write paths — checkout, an admin status change, a refund, a capture, and the
importer — and a cache any one of them can forget to update is a cache that is
wrong without saying so. There is already one source of truth and it is correct
by construction. A second one that agrees most of the time is worse than no
second one, because it gets believed.

Not dropped, because the live server is a MySQL holding real customer rows,
reachable only through signed zip packages with no shell access. An irreversible
`DROP COLUMN` there buys nothing that a rule does not.

So they stay in the table and are made unmistakable in the code:

- `Customer::UNMAINTAINED_COLUMNS` names all three, with the reasoning attached.
- All three are in `Customer::$hidden`, so no endpoint can serialise a
  confident-looking `0` that is indistinguishable from a customer who has
  genuinely never ordered. `/api/*` on this app is unauthenticated, so that
  matters more here than it would elsewhere.
- Their casts are removed. Casting a column implies something reads it.
- `tests/Feature/CustomerDerivedColumnsTest.php` fails if any of that is undone.

**No change is needed to the Customers screen.** It already reads the right
thing. This decision is the one that keeps it right.

### 2.2 `addresses` has no external-id column — **CONFIRMED, and fixed**

`customers.wp_user_id` and `orders.wc_order_id` exist, nullable and unique.
`addresses` had nothing. Re-running an address import duplicated every row.

Fixed by `2026_09_22_000000_add_import_external_ids.php`, which adds
`addresses.source_key` — a `varchar(191)`, nullable, with a unique index.

It is a string and not an integer on purpose. WooCommerce has no addresses
table: billing and shipping live in `wp_usermeta` as loose keys, and an order's
addresses are order meta. There is no integer that identifies an address, so an
`unsignedBigInteger` here would be a fiction the importer had to invent values
for. **The composition rule is the importer's contract:**

```
user:<wp_user_id>:billing
user:<wp_user_id>:shipping
order:<wc_order_id>:billing
order:<wc_order_id>:shipping
```

Any stable scheme works as long as it is deterministic across runs. This one is
readable in a database client, which matters when something goes wrong at 2am.

`source_key` is in `Address::$fillable` deliberately, and unlike `customer_id`,
which stays guarded. Eloquent fills `updateOrCreate()`'s match attributes
through the same guard as everything else, so a guarded `source_key` would have
produced an address with a null key on every pass — exactly the duplication the
column exists to prevent. Nothing a shopper submits reaches it: the storefront
address book writes a validated allowlist and leaves it null, which is what an
address with no WordPress origin should say.

### 2.3 `customers.email` is `UNIQUE NOT NULL` — **CONFIRMED**

`$t->string('email')->unique();` with no `->nullable()`. Both halves bite:

- A Woo order with no email cannot produce a customer row.
- Two Woo users sharing an email cannot both become customer rows. And since
  `wp_user_id` is also unique, they cannot be collapsed onto one row without
  discarding one of the two WordPress identities.

**Recommendation: keep the constraint. Handle it in the importer.**

The constraint is load-bearing. `Customer::firstOrCreate(['email' => ...])` is
how the storefront checkout finds a returning shopper, and `email` is the login
identity. Relaxing it to nullable or non-unique would let a second customer row
appear for an address that already has one, and the shopper would find their
order history had vanished.

So:

- **No email (D1):** synthesise a deterministic placeholder —
  `wc-order-<wc_order_id>@import.invalid`. `.invalid` is reserved by RFC 2606
  and can never be delivered to, so a stray mailing cannot reach a real person.
  Deterministic means a re-run finds the same row rather than making another.
  The alternative — leaving the order unlinked — is also defensible and is what
  section 2.4 is about.
- **Shared email (D2):** keep the customer with the lower `wp_user_id`, attach
  the other's orders to it, and record the discarded `wp_user_id` in
  `customers.notes` so the decision is visible afterwards. This is lossy and
  needs the owner's agreement before it runs.

**Lowercase every email on the way in.** Checkout stores
`mb_strtolower($data['billing_email'])`. An imported `Buyer@Example.com` would
not match a later checkout as `buyer@example.com`, and the shopper would get a
second customer row. This is also the sharpest MySQL/SQLite divergence in the
whole import: MySQL's default `utf8mb4_..._ci` collation is case-insensitive, so
`A@x.com` and `a@x.com` **collide** on the unique index there and insert happily
on SQLite. An importer tested only against the test suite will hit this for the
first time on the live server.

### 2.4 Guest orders with `customer_id` NULL — **CONFIRMED, with a correction**

Confirmed that such orders sit outside every per-customer total: each of those
figures is a join through `customers` and cannot see a row with a null
`customer_id`.

**The survey was wrong that they are invisible.** `CustomersApiController`
already reports them, as `unlinked_orders` — a count of orders with no customer
in `Order::REAL_STATUSES` — and the screen states it. Store-wide revenue is a
plain aggregate over `orders` and is unaffected.

So the exposure is narrower than reported but real: per-customer spend is
correct for the customers it can see, and simply does not describe guest
revenue. On a store where most checkouts are guest checkouts, that is most of
the money, and the two numbers disagree by design.

**Recommendation (D3): create a customer row per distinct guest email at import
time, and link the orders to it.** This is what the storefront's own checkout
already does — `Customer::firstOrCreate()` makes a row for a shopper who does
not sign in, so a guest here is a customer row with no usable credential rather
than a missing row. Importing guest orders as unlinked would create a second
class of guest that the application does not otherwise produce.

Use the real billing email where there is one; fall back to the D1 placeholder
where there is not. Orders whose email is a placeholder should keep
`customer_id` NULL rather than clustering every emailless order onto one
synthetic customer.

`tests/Feature/ImportContractTest.php` pins that store revenue includes a guest
order and that `unlinked_orders` sees it.

### 2.5 `orders.created_at` is what "last order" reads — **CONFIRMED**

`CustomersApiController` computes
`MAX(CASE WHEN status IN (...) THEN created_at END) as paid_last_at`. It is
`created_at`, not `paid_at`.

An importer that lets Eloquent stamp `created_at` with `now()` does not merely
lose the order date. It makes every customer in the store look like they bought
something today, which reverses the meaning of every recency segment and sort on
the Customers screen at once — and does it silently, because the data looks
perfectly well-formed.

**Recommendation: set `created_at`, `updated_at` and `paid_at` explicitly on
every imported order from the Woo `date_created`, `date_modified` and
`date_paid`.** Set `completed_at` from `date_completed` where the order has one.
Timestamps are UTC in this schema; convert from the store's WordPress timezone
rather than assuming they match.

Do the same for `customers.created_at` (from `user_registered`) — the Customers
screen's "registered" column, date filters and `oldest`/`newest` sorts all read
it, and an import that stamps today makes a four-year-old customer base look
like it was acquired this morning.

`tests/Feature/ImportContractTest.php` pins that an explicitly-set historical
`created_at` survives the write, survives re-reading through the model, and is
what the `MAX(created_at)` aggregate reports.

---

## 3. The gap nobody reported: the unique indexes may not exist on the server

This is the most consequential finding in this lane, and it is invisible from
the test suite.

`2026_09_15_020000_repair_order_tables.php` and
`2026_09_21_000000_repair_customer_tables.php` re-add columns that a broken
`->after()` chain had left missing on the live MySQL. They add them as bare
nullable columns:

```php
'wc_order_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wc_order_id')->nullable(),
'wp_user_id'  => fn (Blueprint $t) => $t->unsignedBigInteger('wp_user_id')->nullable(),
```

with **no `->unique()`**. That was the right call for those migrations — their
job was to stop the 500s, and a unique index is exactly the thing that can
refuse to be added to a populated table. But it means the live server can be
carrying `orders.wc_order_id`, `customers.wp_user_id` and
`product_variants.wc_id` as ordinary nullable columns with no constraint behind
them at all.

A readiness check that asks "does the column exist?" answers yes. The importer
matches on it happily. The second pass inserts a complete second copy of every
order in the store.

A fresh SQLite migrated from the Phase 0 schema **has** the unique indexes,
which is precisely why no test could have found this.

`2026_09_22_000000_add_import_external_ids.php` therefore also ensures the
unique constraint exists on `orders.wc_order_id`, `orders.order_number`,
`orders.invoice_number`, `customers.wp_user_id`, `products.wc_id`,
`product_variants.wc_id`, `categories.source_term_id` and
`brands.source_term_id`. Each is a no-op on a fresh database and the whole point
on the server.

It checks for an existing unique constraint **by column set, not by index
name** — Phase 0's index is called `orders_wc_order_id_unique` and this
migration's short name is `orders_wc_order_unique`, so a name-only check would
have added a redundant second unique index over the identical column on every
fresh install, slowing exactly the writes an import does most of.

Where a column already holds duplicates, the index is **skipped**, the duplicate
value is printed, and the migration continues. A unique index refused by the
data would abort the migration and every later one in the same package. A named
problem the owner can fix in a minute beats a failed deployment.

### Other divergences between the Phase 0 schema and the repair migrations

Worth knowing, because which one your server has depends on when each column was
created:

| Column | Phase 0 | Repair migration |
|---|---|---|
| `addresses.country` | `varchar(2)`, default `AE` | `varchar(255)`, nullable |
| `orders.currency` | `varchar(3)`, default `AED` | `varchar(255)`, default `AED` |
| `orders.invoice_number` | `unsignedBigInteger`, unique | `integer`, nullable, no unique |
| `customers.total_spent` | `unsignedBigInteger` | `integer` (signed) |
| `customers.orders_count` | `unsignedInteger` | `integer` (signed) |
| `carts.token` | `uuid`, unique | `varchar(255)`, nullable, no unique |

The `addresses.country` one is the trap: an importer writing a three-letter
country code succeeds on a repaired server and is **silently truncated to two
characters** on a Phase 0 one. Use ISO 3166-1 alpha-2, which is what the schema
intends and what the shipping-zone tables match on.

---

## 4. Table reference

Money is **integer fils (AED × 100)** in every column marked *fils*. Never a
float, at any point in the pipeline — not in the JSON, not in the intermediate
value, not in the insert.

### Catalogue

| Table | External id | Unique constraints | Must be set |
|---|---|---|---|
| `categories` | `source_term_id` (unique) | `slug`, `source_term_id` | `slug`, `name`, and **`depth` + `path`** — the URL path is cached, not computed, so nesting breaks without them. `parent_id` self-FK. |
| `brands` | `source_term_id` (unique) | `slug`, `source_term_id` | `slug`, `name` |
| `tags` | `source_term_id` (unique) | `slug`, `source_term_id` | `slug`, `name` |
| `attributes` | **none** — match on `slug` | `slug` | `slug`, `name`. `is_variation_axis` must be true for anything variants are defined by, or variant selection has no axis. `query_var` preserves the old filter URL contract. |
| `attribute_values` | `source_term_id` (**unique as of this lane**; was index only) | `source_term_id`, `(attribute_id, slug)` | `attribute_id`, `slug`, `name` |
| `products` | `wc_id` (unique) | `slug`, `wc_id` | `slug`, `name`, `price`/`sale_price` *(fils)*, `status` (`publish`/`draft`/`private` — not `active`), `stock_status`, `position`. `sku` is indexed but **not** unique. `wc_id` is a URL contract: `?add-to-cart={id}` links are live in the wild. |
| `product_variants` | `wc_id` (unique) | `wc_id` | `product_id`, `price` *(fils)*, `stock_status` |
| `category_product`, `product_tag`, `product_attribute_value`, `product_variant_attribute_value` | composite primary keys | the composite PK | Naturally idempotent — re-inserting the same pair fails rather than duplicating. Use upserts that tolerate that. |

### Customers

| Table | External id | Unique constraints | Must be set |
|---|---|---|---|
| `customers` | `wp_user_id` (unique) | `wp_user_id`, **`email` (`UNIQUE NOT NULL`)** | `email` (lowercased — see 2.3), `created_at` (see 2.5). `legacy_password` takes the WordPress phpass or wp-bcrypt hash so imported customers sign in with their existing password and are upgraded to bcrypt on first login; leave `password` NULL. **Do not write `orders_count`, `total_spent` or `last_order_at`.** |
| `addresses` | `source_key` (**new, unique**) | `source_key` | `customer_id` (NOT NULL), `type` (`billing`/`shipping`), `country` as ISO alpha-2. `is_default` on one address per type, or the account area has no default to show. |
| `users` (admin/staff) | `wp_user_id` (**new, unique**) | `wp_user_id`, `email` | `name`, `email`, `password`. **No `legacy_password` column exists here** — WP admin hashes cannot be carried, so every imported admin needs a reset (D4). |

### Orders

| Table | External id | Unique constraints | Must be set |
|---|---|---|---|
| `orders` | `wc_order_id` (unique) | `wc_order_id`, `order_number`, `invoice_number` | `order_number` (NOT NULL, unique — see D5), `email` (NOT NULL), `status`, all money columns *(fils)*, and **`created_at` / `updated_at` / `paid_at` from the Woo dates** (2.5). `status` is free-form so Woo custom statuses (`shipped`, `tamara-p-failed`) survive; only `Order::REAL_STATUSES` count as revenue. `billing_address` / `shipping_address` are JSON snapshots. `customer_id` nullable (2.4). |
| `order_items` | `wc_item_id` (**new, unique**) | `wc_item_id` | `order_id` (NOT NULL), `name` (NOT NULL — a snapshot, so the order still reads correctly after the product is renamed or deleted), `quantity`, `unit_price`/`subtotal`/`total` *(fils)*. Without the external id a re-run gives each order a second set of lines: item revenue doubles while the order total stays right, which is inconsistent rather than obviously broken. |
| `order_notes` | `source_comment_id` (unique) | `source_comment_id` | `order_id`, `content` |
| `refunds` | `wc_refund_id` (unique) | `wc_refund_id`, `idempotency_key` | `order_id`, `amount` *(fils)*, `status` |
| `coupon_redemptions` | **none** | — | Cannot be matched on re-run. If historical redemptions are imported, either give this table an external id first or import it exactly once. `order_id` and `email` are indexed only. |

### Reviews, content, commerce settings

| Table | External id | Unique constraints | Must be set |
|---|---|---|---|
| `reviews` | `(source, source_id)` (**unique as of this lane**; `source_id` was index only) | `(source, source_id)` | `source` (`sorina` or `wp_comment`), `source_id`, `rating`, `status`. The pair is the key, not `source_id` alone: the two origins have unrelated id spaces that will overlap. `product_id` NULL means a business review (WordPress `product_id` 0). |
| `coupons` | `wc_id` (unique) | `wc_id`, `code` | `code`, `type`, `amount` *(percent ×100, or fils)*, and **`usage_count` verbatim** so an exhausted code cannot be re-used after cutover. |
| `shipping_zones` | `source_zone_id` (unique) | `source_zone_id` | `name` |
| `shipping_zone_locations`, `shipping_methods` | **none** | — | Few enough rows to import once by hand. `min_amount` is the free-shipping threshold *(fils)* and belongs here, never in a constant. |
| `media` | `source_attachment_id` (unique) | `source_attachment_id` | `filename`, `path` — site-relative and kept under `/wp-content/uploads/` so no shared image URL breaks. |
| `posts`, `pages` | `source_post_id` (unique) | `source_post_id`, `slug` | `slug`, `title`, `status` |
| `menus` | `source_term_id` (unique) | `source_term_id`, `slug` | `slug`, `name`, `location` |
| `menu_items` | `source_post_id` (unique in Phase 0; the repair migration re-adds it **without** the unique) | `source_post_id` | `menu_id`, `label` |
| `redirects` | **none** — match on `source` | `source` | `source`, `target`, `code` |

Not imported: `carts`, `cart_items`, `payments`, `payment_events`,
`payment_providers`, `settings`, `module_toggles`, `module_settings`,
`quiz_submissions`, `tax_rates` (VAT is display-only under D-64).

---

## 5. Cross-cutting rules

1. **Match on the external id, never on a name, slug, email or SKU.** Slugs get
   edited, emails get corrected, SKUs repeat. The external id is the only value
   that means "this is the same row".
2. **Money is integer fils everywhere.** Multiply by 100 in the exporter, in
   integer arithmetic, and assert the result is an integer before writing.
   `9950` fils is AED 99.50; `99` is not.
3. **Dates are explicit and UTC.** Never let Eloquent stamp a historical row.
4. **Emails are lowercased** before they touch `customers.email` (2.3).
5. **Country codes are ISO 3166-1 alpha-2** (section 3).
6. **Wrap each entity's pass in a transaction, not the whole import.** A
   two-hour import that rolls back entirely on row 400,000 cannot be resumed.
7. **`Setting::map()` memoises in a process-level static as well as the cache.**
   A long-running import process will not see settings written after its first
   read. Re-read deliberately or run the import in short-lived processes.
8. **Run the import against a restored copy of the live MySQL, never against
   SQLite.** Three of the failures described here — the case-insensitive email
   collision, the missing unique indexes, and the `varchar(2)` country
   truncation — cannot occur on SQLite and cannot be avoided on MySQL.

---

## 6. What this lane changed

- `database/migrations/2026_09_22_000000_add_import_external_ids.php`
  - adds `addresses.source_key`, `order_items.wc_item_id`, `users.wp_user_id`,
    each nullable with a unique index;
  - upgrades `attribute_values.source_term_id` from an index to a unique
    constraint, and adds a unique `(source, source_id)` on `reviews`;
  - ensures the unique constraint exists on the eight external ids that the
    repair migrations may have left unconstrained on the live server
    (section 3);
  - guards every add on `Schema::hasColumn`, every index on the actual
    constraint present in the driver, and skips — loudly — any index the
    existing data cannot satisfy;
  - uses no `->after()` anywhere.
- `app/Models/Customer.php` — `UNMAINTAINED_COLUMNS`, the three columns hidden
  from serialisation, their casts removed (section 2.1).
- `app/Models/Address.php` — `source_key` added to `$fillable`, with the reason.
- `app/Console/Commands/ImportCatalog.php` — audited; see section 7.
- `tests/Feature/ImportContractTest.php`,
  `tests/Feature/CustomerDerivedColumnsTest.php`.

Nothing in `routes/`, the admin views, or `CustomersApiController` was touched.
The Customers screen needs no change: it already reads the aggregate rather than
the dead columns, which is the behaviour section 2.1 makes permanent.

---

## 7. Audit: `kbb:import-catalog`

**It does nothing, and there is no other importer in this repository.**

`handle()` has returned a one-line notice since Phase 0 and never touched the
database. Its description still advertised "Import products from a WooCommerce
JSON export (idempotent on wc_id)", which is why it reads as a starting point
and is not one. The "WordPress Migrator" the old notice deferred to does not
exist here either — no command, no service, no controller.

The file also carried a private `handleLegacy()` of about sixty lines that
nothing called. It read as a complete, working product importer. It was not:

- It wrote `brand`, `category`, `concerns`, `in_stock`, `reviews`,
  `images_json`, `variants_json`, `collections_json`, `labels_json`,
  `custom_tabs_json` and `variant_axis`. **Not one of those columns exists.**
  Phase 0 replaced the flat products table — brand and category are real foreign
  keys to real tables, images and custom tabs are JSON columns under different
  names, and reviews are their own table.
- It assigned `$p['price']` straight through from JSON. Prices are integer fils.
  A decimal in the export would have been truncated to whole fils — AED 99.50
  landing as 99 fils, on every product, silently.
- It defaulted `status` to `'active'`, a value this schema has no concept of.

It **did** match on `wc_id`, which is the one thing it got right.

`handleLegacy()` has been removed. Dead code that looks like a working importer
is a trap set for whoever runs the real migration under time pressure: it is the
obvious thing to re-enable, it would have failed on the first row, and had the
schema drifted the other way it would have succeeded and mangled every price
instead. The command now says what it is and points here.
