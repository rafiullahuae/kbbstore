# The WooCommerce export at the shop's real volume

```bash
php tools/woo-volume-fixture/generate.php <out-dir>
php tools/woo-volume-fixture/generate.php /tmp/small --products=40 --orders=120 --customers=90
```

671 products, 4,159 orders and 3,712 customers — the three numbers Phase 13
names — plus the categories, brands, coupons, line items, reviews and Yoast rows
that come with them, and four files nothing opens.

**Why it is checked in.** `tests/Fixtures/woo` is nine rows of deliberately nasty
data and it proves every mapping and every refusal. It cannot prove anything that
is a property of volume: wall clock, peak memory, how many queries an order
costs, whether a killed run resumes cleanly, whether the second pass really
reports every row unchanged. Those need more rows than one batch, an entity that
stops part-way, and totals big enough that an error of one row shows.

**Deterministic.** A fixed seed and no calls to `rand()`, so the same seed writes
byte-identical files — which is what makes the resume test meaningful, since the
checkpoint fingerprint is over file content.

**The defects are a density, not a list of row numbers**, so the same generator
at a smaller size still carries one of each: trashed products, duplicate and
missing SKUs, prices carrying fils, stripped `<script>` tags, guest orders,
orders with no email, orders naming a WordPress user who is not in the export,
foreign currency, uneven unit prices, negative refund quantities, three-letter
country codes, two WordPress users sharing an email, bare coupon expiry dates,
fixed coupons carrying fils, reviews of a product that is not there, and
comments in the WordPress trash. `manifest.json` records exactly how many of
each it wrote, so a test can assert against a number it did not read out of the
report it is checking.

Used by `tests/Feature/ImportAtVolumeTest.php` and
`tests/Feature/ImportCountVerificationTest.php`; the full-volume rehearsal is
written up in `docs/FV-IMPORT-AT-VOLUME.md`.
