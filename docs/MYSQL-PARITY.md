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

## Still unverified

- The live host's actual MySQL version, `sql_mode`, collation and
  `innodb_default_row_format` are unknown. The suite is pinned to the strictest
  realistic setting, which is a floor, not a copy of production.
- Identifier length and index key length were exercised only by this migration
  set on this engine. `migrate:fresh` is clean on MySQL 8.0.46 and MariaDB
  10.11, so no 64-character identifier and no 3072-byte index key is currently
  over the line, but a new long `VARCHAR` unique index would pass on SQLite and
  fail here.
- MySQL's default collation is case-insensitive and SQLite's `=` is not. No test
  currently depends on the difference in either direction, so nothing pins it;
  code that relies on case-sensitive matching of an email or a slug would behave
  differently in production and the suite would not say so.
