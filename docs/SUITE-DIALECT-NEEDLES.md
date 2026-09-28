# Matchers that spell SQL for one engine, and what happened when we looked

*28 September 2026, integrator.*

## What was wrong

Illuminate quotes identifiers per driver. The same query is logged as

```
select "id" from "products" where "status" = ?        (sqlite)
select `id` from `products` where `status` = ?        (mysql)
```

The default lane is SQLite, so almost every SQL matcher in this suite was
written against the first spelling. Under `-c phpunit-mysql.xml` — the engine
the shop actually runs — those needles match **nothing**, and what that costs
depends entirely on which way the assertion points:

| Assertion shape | On MySQL |
|---|---|
| `expect($count)->toBe(3)` | fails loudly. The lucky case. |
| `expect($count)->toBe(0)` | **passes vacuously.** |
| `expect($a)->toBe($b)` where both are counts | **passes vacuously**, `0 === 0`. |

Lane MY built `Tests\Support\SqlShape::portable()` for exactly this and adopted
it in two files. Nothing made the rest adopt it. Three matchers were still
spelled one-engine-only:

- **`CartVariantWasPriceTest:513`** counted `from "products"` into `$one` and
  `$five` and asserted `expect($five)->toBe($one)`. On MySQL both were `0`, so
  the struck-price N+1 guard compared nothing with nothing — it would have
  passed with the cart asking the parent product once per variation, which is
  the exact defect the test was written for.
- **`SeoLegacyAddressesLandTest:502`** counted `from "redirects"` and asserted
  `toBe(0)` on three warm pages. On MySQL that was `0` whatever
  `CheckRedirects` did.
- **`SeoLegacyAddressesLandTest:490`**, the counter beside it, was worse than
  mis-spelled: `$seen` and `$before` were assigned and **never asserted at
  all**. Dead measurement code that reads as a guard.

None of the three was a bug on the shop. Both files pass on MySQL with real
counts now, which is the same answer Lane MY got for the brands guard: the
eager loads were right all along and the guards were not guarding them.

## What was done

1. All three matchers now run the statement through `SqlShape::portable()`.
2. Each now proves its own needle before any zero is believed:
   - `SeoLegacyAddressesLandTest` issues one deliberate read of `redirects` and
     requires the counter to see it — `expect($redirectReads(fn () =>
     DB::table('redirects')->count()))->toBe(1)`. The dead `$seen`/`$before`
     pair is gone.
   - `CartVariantWasPriceTest` requires `$one > 0` before comparing it to
     `$five`.
3. `tests/Feature/SqlNeedleDialectGuardTest.php` sweeps the whole suite so a
   fourth one cannot be written. A file that spells a table the SQLite way in a
   matcher must either call `SqlShape::portable()` in **code**, or carry the
   backticked spelling of the same `from`/`join`/`into`/`update` pair.

### The guard took two tries to have teeth, and both misses are worth reading

- **Prose exempted its own file.** The first cut asked whether the file
  contained the string `SqlShape::portable` anywhere. The comment *explaining
  why a needle had been wrapped* satisfied that, so reverting the wrap left the
  guard green. It now counts only non-comment lines.
- **A code span in an error message exempted it too.** The second cut accepted
  a file that mentioned the table in backticks anywhere — and
  `CartVariantWasPriceTest`'s own failure message reads *"five variable basket
  lines read \`products\` {$five} times"*, which is English, not a MySQL needle.
  The escape hatch now mirrors the whole keyword-and-identifier pair,
  ``from `products` ``.

The sweep also carries its own non-vacuity check: it fails if it finds fewer
than eleven needles in the suite, so a regex that stops matching is a failure
rather than a pass.

**Mutation, run both ways.** Take `SqlShape::portable(` back out of
`SeoLegacyAddressesLandTest`:

```
default config          21 passed
-c phpunit-mysql.xml     1 failed, 20 passed
      "the needle did not see a statement that reads `redirects` on this
       engine, so every zero it reports below would be vacuous"
```

Red only on the engine the shop runs — which is the whole asymmetry.
`SqlNeedleDialectGuardTest` names the file and line on either engine.

## What was NOT reproduced, and should not be taken on trust

`MediaLibraryTest` carries a note from the media round reporting that
**thirteen Feature files issue DDL** and that leaked rows accumulate through a
MySQL run — measured at the time as `media` holding 107 rows where the case had
created 3. The stated mechanism is that MySQL implicitly commits the open
transaction when a statement is DDL, so `RefreshDatabase`'s rollback has
nothing left to roll back.

**Half of that is confirmed and half of it did not reproduce.**

The engine behaviour is real. Straight at the server:

```sql
CREATE TABLE t (id INT);
START TRANSACTION;
INSERT INTO t VALUES (1);
CREATE TABLE scratch (id INT);   -- implicit commit
ROLLBACK;
SELECT COUNT(*) FROM t;          -- 1
```

The suite behaviour did not. A two-test probe — test A writes a row, issues
`Schema::create()` and `Schema::dropIfExists()`, test B counts — reported
**`leaked_rows=0` on MySQL and on SQLite alike**, with `Schema::hasTable()`
answering false for the scratch table in test B, i.e. the schema test A left
behind was gone by the time test B ran. Whatever wipes it between two tests of
one process is not accounted for by the note.

So the 107 rows have an unexplained cause. It is **not** safe to "fix" the
thirteen DDL files on the strength of the note: the mechanism that would
justify the fix does not show up when it is asked directly. Reproducing it
needs an instrumented **full** MySQL run — the shape it was originally seen in
— naming the first test to see a row it did not create. That is a lane's task,
not a guess.

Only `grep` finds nine files issuing DDL today, not thirteen, which is one more
reason to re-measure rather than inherit the number.
