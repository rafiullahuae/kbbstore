# Running the suite against MySQL

Tests run on SQLite. The store runs on MySQL. SQLite is the more permissive of
the two, so a statement MySQL rejects outright comes back `200` in the suite and
the run stays green. That gap is not theoretical — it has produced four
confirmed production defects, and none of them was visible to the SQLite run.

## The one-line command

```bash
vendor/bin/pest -c phpunit-mysql.xml --compact
```

`phpunit-mysql.xml` is `phpunit.xml` pointed at `127.0.0.1:3306`, database
`kbb_test`, user `kbb`, password `kbb`. Every `<env>` in it carries
`force="true"`; without that, PHPUnit leaves an already-exported `DB_CONNECTION`
alone and the run silently falls back to SQLite, which is exactly the false
green the file exists to prevent.

To get a server locally:

```bash
apt-get install -y mysql-server-8.0
mkdir -p /var/lib/mysql-files && chown mysql:mysql /var/lib/mysql-files
mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
mysqld --user=mysql --datadir=/var/lib/mysql \
       --socket=/var/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --mysqlx=0 &

mysql -uroot -h127.0.0.1 -e "
  CREATE DATABASE kbb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'kbb'@'%' IDENTIFIED WITH mysql_native_password BY 'kbb';
  GRANT ALL ON kbb_test.* TO 'kbb'@'%'; FLUSH PRIVILEGES;"

php artisan migrate:fresh --force   # with the DB_* env from phpunit-mysql.xml
```

CI does the same thing in the `mysql` job in `.github/workflows/ci.yml`, against
a `mysql:8.0` service container. That job is **required, not advisory**: a
dialect bug CI reports but does not block is a dialect bug that ships.

## sql_mode

The live host's `sql_mode` is unknown, so the suite is pinned to the strictest
realistic setting rather than to a guess:

```
ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,
ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

Laravel's `strict => true` on the `mysql` connection issues exactly that per
session, so no config change is needed; CI also sets it globally so `artisan`
and the `mysql` client match. `SqlDialectGuardTest` asserts the connection
really arrived strict instead of trusting the config file — without
`ONLY_FULL_GROUP_BY` the 1140 below never fires, and without
`STRICT_TRANS_TABLES` a bad value is truncated with a warning rather than an
error, which is the same false green in a different costume.

## MariaDB is not a stand-in for MySQL

Both were run while this was being written. MariaDB 10.11 passed a build that
MySQL 8.0.46 failed: MariaDB returned the plain 19-character datetime that the
code compared against, and MySQL 8 widened the same expression to `DATETIME(6)`.
Use `mysql:8.0`.

## What has actually gone wrong

Each of these passed on SQLite while broken in production.

### 1. Aggregate mixed with bare columns — `SQLSTATE[42000] 1140`

> Mixing of GROUP columns (MIN(),MAX(),COUNT(),...) with no GROUP columns is
> illegal if there is no GROUP BY clause

`selectRaw()` **appends** to the select list, it does not replace it. A
`(clone $base)->toBase()->selectRaw('COUNT(*) ...')` therefore keeps every
column the row query selected and staples an aggregate after them, with no
`GROUP BY`. SQLite invents a row for the bare columns; MySQL refuses.

The same 1140 fires on a leftover `ORDER BY`, which is how the first fix was
incomplete: dropping the select columns was not enough while
`order by customers.created_at desc` was still attached. Clearing the columns
also means clearing their bindings, or the driver gets more values than the
statement has placeholders.

### 2. Pagination inherited by an aggregate — wrong on every driver

The Customers screen ran `forPage()` on the same Builder it later handed to the
summary query, so the aggregate carried `offset 25` on page two. An aggregate
returns one row; skip any rows and there is none, so every tile across the top
of the screen read zero on every page but the first. SQLite answered `200`
throughout. This one was never a dialect bug — the dialect guard is just what
made it visible.

### 3. Ciphertext in a JSON column — `4025` / `3140`

`payment_providers.config` and `mail_credentials.config` were declared with
`$t->json()` and carry an `encrypted:array` cast. That cast stores
`base64(json_encode([...]))` — a base64 blob, not JSON. A JSON column is
validated on every write:

| engine | what `json()` compiles to | error |
| --- | --- | --- |
| SQLite | `text` | none; anything is accepted |
| MariaDB 10.x | `longtext ... CHECK (json_valid(...))` | `SQLSTATE[23000] 4025` |
| MySQL 8.x | native `JSON` | `SQLSTATE[22032] 3140 Invalid JSON text` |

So on the production host every save of a gateway key or an SMTP password
failed. 110 of 558 tests fail against a real MySQL for this one reason.
`2026_09_22_000000_widen_encrypted_config_columns` widens both to `longText`.

**Rule:** a column holding an `encrypted:*` cast is never `json()`.

### 4. A sentinel compared as text — MySQL 8 only

`CustomersApiController` marks "no activity ever" with `1970-01-01 00:00:00`
and `iso()` turned that back into `null` with an exact string compare. A `CASE`
takes the widest type of its branches, and on MySQL 8 those branches are
`DATETIME(6)`, so the sentinel came back as `'1970-01-01 00:00:00.000000'` — 26
characters. The compare missed and every customer who had never ordered showed
a last-active date of **1 Jan 1970**. SQLite and MariaDB both return the plain
19-character string.

**Rule:** compare datetimes as instants, never as strings. Column precision and
the driver both change the text.

### 5. `->after()` in a migration

`ALTER ... AFTER` a column that does not exist is an error on MySQL and ignored
by SQLite — and a `Schema::hasColumn` guard makes the failure look like a clean
no-op, so the migration records as run having changed nothing. Already pinned by
`tests/Feature/MigrationConventionTest.php`; column order is cosmetic and new
migrations do not get to use it.

### 6. A column width SQLite was never told — `SQLSTATE[22001]` / `1406`

> Data too long for column 'token' at row 1

SQLite does not enforce `VARCHAR` or `CHAR` length **at all**, and not because
it is lenient at write time: Illuminate's `SQLiteGrammar` compiles `string()`,
`char()` **and** `uuid()` to the bare word `varchar`, with no length on it, so
the width never reaches the table. MySQL compiles the same three to
`varchar(n)` / `char(n)` and, under `STRICT_TRANS_TABLES`, refuses an over-long
value outright.

`carts.token` is `uuid()`, therefore `char(36)`. Five `CartFooterTest` cases
fabricated `Str::random(40)` for it — green on SQLite, red on all five here.

**The shop itself was never at risk, and that had to be established rather than
assumed.** Every production write of that column is `(string) Str::uuid()`,
exactly 36 characters — `CartService::create()`,
`ManualOrderBuilder::buildDraftCart()`, `PageCostDataset`. The cookie token is
only ever read back as a `WHERE` value, never written. So no shopper has lost a
basket to this and no row is short. It was the fixture that was wrong, and the
fix was to write what production writes.

**Rule:** a `uuid()` column holds a UUID. Nothing else is 36 characters by
accident, and everything people reach for instead — `Str::random(40)`,
`bin2hex(random_bytes(20))` — is longer.

### 7. DDL commits the transaction `RefreshDatabase` is holding

**MySQL has no transactional DDL.** `CREATE TABLE`, `DROP TABLE` and `TRUNCATE`
each cause an **implicit commit** of whatever transaction is open. SQLite's DDL
is transactional, so the same statement rolls back with everything else. Reduced
to the two engines, same four statements:

```
begin; insert; DROP TABLE scratch; rollback

sqlite -> rollback ok,    0 rows survive
mysql  -> rollback THREW "There is no active transaction", 1 row survives
```

`RefreshDatabase` isolates tests by wrapping each one in a transaction and
rolling it back. A test that issues DDL has therefore **already committed
everything it wrote** by the time the rollback is attempted, the rollback is a
no-op, and those rows are in the database for the rest of the process. Thirteen
Feature files issue DDL, each of them legitimately.

**Measured rather than reasoned, and stated no further than the measurement
goes.** A probe in `tests/Pest.php`'s `beforeEach` recorded the first test to see
any `media` row at its own start — which, under `RefreshDatabase`, can only be a
row some earlier test committed. In a full MySQL run that was
`DemoOrdersExcludedFromReportingTest`'s *"leaves every reported figure unchanged
when demo orders are imported for real"*, with **5 rows** already visible, and the
test immediately before it was the same file's *"reports normally when the demo
log table does not exist"* — which calls `Schema::dropIfExists(DemoSeed::TABLE)`,
because that is precisely the state it needs to test.

One thing in that picture is NOT explained and is left open rather than smoothed
over: the five leaked rows carry `uploads/ugc/...` paths, which the demo-orders
file has no reason to write. So either the commit boundary is wider than one test
or something in that path registers media; the isolated harness reproduction
below is unambiguous about the MECHANISM, and the exact provenance of those five
rows is not yet closed. A standalone probe — 30 media rows, then
`Schema::create()` + `Schema::drop()`, under this suite's own
`RefreshDatabase` — left **all 30 in the database after the run**, on MySQL, in
this repository. That is the mechanism, confirmed in the harness and not only at
PDO level.

**What it actually broke.** `MediaLibraryTest`'s *"filters by date inclusively at
both ends"* was red in a full MySQL run and green on its own, on both engines.
Measured at the moment it failed: the `media` table held **107 rows where the
case had created 3**, `/admin-api/media` reported `total=106 pages=5
per_page=24`, and the row the assertion looked for was on page 5.
`toContain()` reads page one, so the date filter was working perfectly and the
row was simply not on the page being read. Nothing about that failure points at
its cause, and nothing about it reproduces alone.

**Rule, and it is the cheap half:** a test that reads a PAGINATED endpoint must
scope the query to rows it created itself — a `q=` token, a filter, an explicit
id — rather than trusting that nothing else is in the table. That is true
whatever leaks, and `MediaLibraryTest`'s own backfill cases already argued for it
in as many words ("the count of rows for a name it just created is exactly as
strong a statement, and it is true whatever else is on disk").

The expensive half is open: nothing detects the leak at its source, so the next
one will surface somewhere equally unrelated. A guard would have to compare
committed state across a test boundary, and it would go red on the thirteen
files below until each one restores what its DDL committed:

```
CustomersScreenTruthTest   DemoOrdersExcludedFromReportingTest  FaqSchemaTest
GoBackgroundImportTest     MailDeliveryLogTest                  MediaLibraryTest
MediaUsagesTest            ModuleRealRulesTest                  OrderTableRepairTest
RoutineSearchAndDemoTest   SuiteIsolationTest                   YoastSeoImportTest
UpdateSurvivesAMissingColumnTest
```

`MediaLibraryTest` is itself on that list, so it leaks its own rows too — which is
the second reason its date case had to stop trusting the table.

### 8. A collation decides the ORDER of a listing, not only a match

`ORDER BY products.name` is collated. SQLite's default is `BINARY`, so it sorts
by byte and **every uppercase letter precedes every lowercase one**; MySQL's
`utf8mb4_unicode_ci` is case-insensitive. On the four SEO preview fixtures that
is the whole difference between

```
sqlite: Medicube Collagen · Medicube Kojic · Medicube PDRN · ilso Deep Clean
mysql : ilso Deep Clean · Medicube Collagen · Medicube Kojic · Medicube PDRN
```

The shop's ordering is neither ambiguous nor at fault —
`ShopController::applyDefaultSort()` ends in a total order and every other sort
carries an id tie-break, for the pagination reason its own comment gives. MySQL's
answer is the one a shopper expects and the one production gives. It simply is
not the one the default test lane sees, so **a test that pins listing order pins
SQLite's byte order rather than the shop's.**

This is what rewrote `docs/SEO-PREVIEWS.html` on every MySQL run: the
`CollectionPage` node's `itemListElement` is the archive's real display order.
That file is generated on the default config now, and a MySQL run asserts all
sixty matrix rows without touching the tracked bytes.

## The structural guard

`Tests\Support\SqlShape` judges the SQL a request **issues** rather than the
answer it returns, by way of `DB::listen`. That works on SQLite, so these guards
run in CI, on a laptop, and in the SQLite suite every lane already runs — no
MySQL required.

```php
$captured = SqlShape::capture(fn () => $this->get('/shop')->assertOk());

expect(SqlShape::violations($captured))->toBe([]);
```

It reports:

- an aggregate mixed with bare columns and no `GROUP BY` (the 1140);
- an aggregate with no `GROUP BY` that still carries an `ORDER BY`;
- an aggregate with no `GROUP BY` carrying a non-zero `OFFSET`;
- bindings that do not match the statement's placeholders;
- `strftime`, `julianday`, `unixepoch`, `sqlite_version`, `sqlite_master`;
- `||`, which concatenates in SQLite and means OR in MySQL;
- a select alias used in `WHERE`, which MySQL cannot see there.

`tests/Feature/SqlDialectGuardTest.php` applies it to the storefront pages and
the admin screens that aggregate, and asserts the rules fire on the exact
statements that took the store down. **Adding a screen to those lists costs two
lines and is the cheapest insurance in the repo.**

## The width guard

The structural guard above judges the SHAPE of a statement. It cannot judge a
width, because on SQLite there is no width to read: the test database genuinely
does not know that `carts.token` is 36.

So `Tests\Support\ColumnWidths` carries a **checked-in fingerprint of the MySQL
schema** — every `char`/`varchar` column of a freshly migrated database, as
`information_schema` reports it — and `tests/Pest.php` runs it over every
statement every Feature test issues, in the `beforeEach` they already share.
An over-wide write is reported with the column, its width, the length it was
given, and the MySQL error it would have produced.

It is installed suite-wide rather than pointed at a list of endpoints, and that
is deliberate: **the defect it was written for was in a fixture**, which no
endpoint-shaped guard would ever have been aimed at. The cost is one
`str_starts_with()` on a statement that is not an `INSERT` or an `UPDATE`, which
is nearly all of them. MEASURED, not assumed: see the figure in the head of
tests/Feature/ColumnWidthGuardTest.php.

A checked-in fingerprint rots, so this one is pinned from the other side:
`tests/Feature/ColumnWidthGuardTest.php` compares it against the live
`information_schema` **whenever the suite runs on MySQL**, in both directions. A
migration that widens a column, narrows one, adds one or drops one fails that
case on the MySQL config — which is a required CI job. The engine that knows the
widths checks the map; the engine that does not know them uses it.

It bails rather than guesses. An upsert, an `INSERT` whose placeholder count is
not a whole multiple of its column count, an `UPDATE` whose `SET` segment holds a
placeholder outside a plain `` `col` = ? `` assignment — all are skipped. A
skipped statement is a missed check; a mis-parsed one fails somebody else's test
for a reason that is not true.

## Two more shapes that are the engine, not the code

Neither is a defect in the shop. Both are tests that asserted the driver and
were rewritten to assert the property, and both were red on this config while
green on SQLite.

### An identifier's quoting

The schema grammar is chosen per driver: SQLite writes `"addresses"`, MySQL
writes `` `addresses` ``. A test that greps a captured query log for the
double-quoted form counts **zero** against a real server.
`CartPageSqueezeTest` did, and failed reading 0 where it wanted 1. Match either
form.

**It came back, so there is now a helper rather than a rule to remember.**
`CartLineEagerLoadTest` (twice) and `CartRecommendedRailSlopeTest` were still
grepping for the double-quoted form and were red here for that reason alone.
`SqlShape::portable()` rewrites a captured statement's backticks to double
quotes, so `DB::listen(fn ($e) => $sql[] = SqlShape::portable($e->sql))` makes
every existing needle work on both engines.

**And watch which DIRECTION the assertion points, because only one of the two
fails loudly.** A positive match reads 0 and goes red, which is the lucky case. A
NEGATIVE one — `expect($q)->not->toContain('"description"')` — is satisfied by
any MySQL statement whatsoever, including one that does select `description`.
`CartRecommendedRailSlopeTest` also compared `$ten['brands']` with
`$one['brands']`, both counted with `from "brands"`: on MySQL that was `0 === 0`,
so an N+1 guard PASSED while comparing nothing with nothing. Normalising the log
made it compare real counts, and it still passes — the eager load was right all
along and the guard was not guarding it. A vacuous green is the failure mode this
whole document exists to find, so prefer normalising the log to spelling both
forms in the needle.

### A query count that includes a schema probe

`Schema::hasColumn()` is **one** select against `information_schema` on MySQL
and **two** pragmas on SQLite. A budget that asserts a single total has pinned
the driver, not the query plan — `RoutineTaggingJobTest` asserted 4 and read 3
here. Count work statements and schema probes separately;
`SqlShape::fromSchemaBuilder()` tells them apart from the call stack rather than
from the text, so it is right on both engines.

### And one that is neither: JSON key order

MySQL 8 stores a native `JSON` column **normalised** — object keys sorted by
length and then by value. SQLite stores the text it was handed. So
`['title' => ..., 'desc' => ...]` round-trips through `posts.seo` in the other
order on a real server, and `toBe()` (`assertSame`) reads that as a failure.
Nothing in the app depends on the order of those keys. Sort before comparing;
keep the strict value compare.

`Tests\Support\KeyOrder::canonical()` does exactly that, and does the one thing
that matters beyond it: it sorts the keys of ASSOCIATIVE arrays and leaves LISTS
in the order they arrived. A list is display order — the categories down a
select, the products in an `itemListElement`, the lines on a basket — and
reordering one is a regression a shopper sees. Sorting lists too would also have
made `ContentPageEditorTest` and `SeoBackOfficePayloadTest` green, and no payload
fixture would have noticed the loss, because a fixture recorded and compared
through the same sort agrees with itself.

Two callers, and the second is not a JSON column at all: `settings` is
`PRIMARY KEY (key)` on a varchar, so InnoDB's clustered index hands
`Setting::map()` its rows in KEY ALPHABETICAL order while SQLite walks the
implicit rowid and returns INSERTION order. Same symptom, same remedy, different
mechanism — an unordered `select` inherits the engine's order, so a map built by
iterating one is engine-ordered too.

## Still unverified

- The live host's actual MySQL version, `sql_mode`, collation and
  `innodb_default_row_format` are unknown. The suite is pinned to the strictest
  realistic setting, which is a floor, not a copy of production.
- Identifier length and index key length were exercised only by this migration
  set on this engine. `migrate:fresh` is clean on MySQL 8.0.46 and MariaDB
  10.11, so no 64-character identifier and no 3072-byte index key is currently
  over the line, but a new long `VARCHAR` unique index would pass on SQLite and
  fail here.
- ~~MySQL's default collation is case-insensitive and SQLite's `=` is not.~~
  **VERIFIED, and it is worse than case.** `utf8mb4_unicode_ci` is
  ACCENT-insensitive as well, so `é` and `e` are one character to `=`. Measured
  through PDO on MySQL 8.0.46 with this suite's own charset and collation, rows
  `'JOSÉ@Example.com'` and `'jose@example.com'`:

  ```
  LOWER(email) = 'jose@example.com'  ->  BOTH rows
  LOWER(email) = 'josé@example.com'  ->  BOTH rows
  ```

  Two consequences, both real and both now pinned by
  `tests/Feature/ReviewImporterEmailCaseTest.php`:

  1. `ReviewImporter::resolveCustomer()` filed a review from `jose@` against the
     customer `josé@` — a different person, whose name the product page then
     prints under an opinion they did not write. Fixed: the statement is a
     candidate net and `mb_strtolower()` in PHP decides, which is what
     `ImportContext::customerIdForEmail()` above it already did.

  2. **`customers.email` is `UNIQUE` under that collation, so the two addresses
     CANNOT BOTH EXIST.** SQLite holds all three spellings; MySQL accepts the
     first and rejects the rest with `SQLSTATE[23000] 1062 Duplicate entry`. The
     importer lowercases every address and pre-checks collisions against a PHP
     map, so a pure CASE collision gets the friendly decision-D2 rejection on
     both engines — an ACCENT collision does not: it passes the map as distinct
     and is refused by the index, arriving as a raw driver message.
     `ImportRunner` catches `QueryException` per row, so the run reports it and
     continues rather than aborting, which bounds it. Worth extending the
     pre-check before the real customer import.

  Because of (2), `CheckoutController::customerForGuestOrder()`'s identical
  over-match is **load-bearing**: narrowing it would turn a wrong-customer link
  into a duplicate-key failure at checkout, since the second row cannot be
  created. That one is a collation decision on the column, not a query fix.
