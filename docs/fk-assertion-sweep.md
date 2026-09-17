# Lane FK — the whole-suite sweep for expectations that cannot fail

A test that cannot fail is worse than no test, because it is counted as
coverage. This is what was checked, what was found, what was repaired and what
was left for its owners.

## What was checked

Read with an AST walk (`nikic/php-parser`, which is in `vendor/` as a
transitive dependency — fine for a one-off audit, deliberately **not** used by
the guard that ships), over every `.php` file under `tests/`:

| | |
|---|---|
| files walked | 356 (359 after the base branch moved; re-swept) |
| `to*` matcher calls | 10,863 |
| `assert*` calls | 2,639 |
| `it()` / `test()` bodies | 3,789 |
| `foreach` loops containing an assertion | 726 |

Four shapes were hunted. A fifth, "a positive `toContain()` handed a sentence as
a needle", was searched for and does not occur.

---

## Shape A — `->not->toContain($needle, $message)`. Ten found, ten repaired.

`Expectation::toContain(mixed ...$needles)` is **variadic**. What reads as a
failure message is a second needle, the positive expectation then fails because
the haystack does not contain that sentence, and `not` passes on that failure.
`toContainEqual()` has the same signature and the same hole; nothing else in
Pest is variadic.

Each one was **proved vacuous before it was touched**: its own needle was
injected into its own haystack and the test was run. All ten stayed green. Each
was then repaired and the injection repeated. All ten went red.

| file | line | what it believed it guarded | now |
|---|---|---|---|
| `MailDeliveryLogTest` | 158 | **a live mail token in the delivery log** | `str_contains(…)->toBeFalse()` |
| `SocialMetaListingPagesTest` | 305 | **five secrets in a page's `<head>`, on four pages** | `str_contains(…)->toBeFalse()` |
| `ApiSecurityTest` | 688 | every gateway config key off `/api/settings` | `in_array(…)->toBeFalse()` |
| `AdminOrdersMysqlSafetyTest` | 195 | no SELECT alias in a WHERE clause | `str_contains(…)->toBeFalse()` |
| `CheckoutHiddenRowsTest` | 496 | not two Totals on screen at once | `in_array(…)->toBeFalse()` |
| `PriceDisplayTruthTest` | 406 | a whole-dirham product grew no decimals | `str_contains(…)->toBeFalse()` |
| `TaxEngineTest` | 530 | an exclusive tax was not rounded into the total | `str_contains(…)->toBeFalse()` |
| `AdminImportScreenTest` | 233 | no import route takes a parameter | `str_contains(…)->toBeFalse()` |
| `BilingualFoundationTest` | 1055 | no identifier on a translatable list | `in_array(…)->toBeFalse()` |
| `AuditNoteRecordsTheMovementTest` | 116 | the audit note is not the no-change sentence | `str_contains(…)->toBeFalse()` |

`docs/lane-fj-out-of-lane.md` §4a listed six of these. The four it did not have
are `AdminOrdersMysqlSafetyTest`, `TaxEngineTest`,
`AuditNoteRecordsTheMovementTest` and — in Lane FJ's own file, beside the two it
did repair — `ApiSecurityTest:688`.

`tests/Feature/ExpectationsThatCannotFailTest.php` now sweeps the suite for the
shape on every run. It tokenises rather than pattern-matching, because a regex
cannot count arguments across a nested call, and it uses `token_get_all()`
rather than the parser above so that an unrelated package dropping a transitive
dependency cannot silently switch the guard off.

## Shape B — a matcher on a constant. Three candidates, two real.

- `CashOnDeliveryTest:297` — `expect(true)->toBeTrue();`. It stated its own
  conclusion. Replaced with `Http::assertNothingSent()`, which asks the
  mechanism that actually enforces the claim, so the line goes red if
  `preventStrayRequests()` is ever taken out of `beforeEach()`.
- `AdminConsoleWriteTokenTest:201` — the same, and worse placed: it sat on the
  branch the test's own title describes ("reads no csrf-token meta tag unless
  the document actually renders one"), so the named case was the one asserting
  nothing. It arrived on the base branch mid-lane and the re-sweep after the
  merge caught it. There is exactly one way that branch can be a lie —
  `str_contains('')` is false for every needle, so a console this process could
  not read takes the path and reports success over a file nobody opened — and
  that is what it asks now.
- `YoastSeoImportTest:366` — `expect(false)->toBeTrue('the row was accepted')`
  inside a `try`, reached only when the expected exception was not thrown. That
  one CAN fail and is the idiomatic fail-if-reached. Left alone.

While repairing the above: `AdminConsoleWriteTokenTest:186` calls
`toBe([], /* a comment where the message should be */)`. Harmless — the comment
is not an argument, so the expectation is a plain `toBe([])` with the default
message — but the intended message is not being shown to anyone. Left for its
owner.

## Shape C — assertions inside a loop that may not run. Reported, not repaired.

726 loops contain an assertion. Narrowing to loops that hold **all** of their
test's assertions, iterate a dynamic expression, and sit in a test with no
count, `toHaveCount`, `toBeGreaterThan` or `not->toBeEmpty` guard anywhere
leaves 91. Most iterate a route registry or a list built from a constant, which
cannot be empty in a migrated database.

These are the ones whose subject is **derived from rendered markup**, where an
empty list is a real possibility and would turn the test green over a page that
rendered nothing at all:

| file | line | subject |
|---|---|---|
| `ResponsiveTilePhotographsTest` | 232, 303 | `tilePhotos(get('/shop')->getContent())` |
| `ResponsiveGalleryPhotographsTest` | 190, 447, 464, 468 | `dnTags($html, …)`, `array_filter($urls)` |
| `CheckoutFieldComponentTest` | 319, 333 | `cfcControls($html)` |
| `ResponsiveTilePhotographsTest` | 421 | `glob(public_path(…)) ?: []` |
| `ImageVariantInvalidationTest` | 190 | `ivVariantPaths(…)` |

The repair is one line each — count the list before walking it, the way
`StorefrontImagesAndHeadingsTest` already does with
`expect(renderedImages($home))->not->toBeEmpty(…)`. Left to their owners: none
of them is empty today, so none is currently vacuous, and nine files across five
lanes is not a change to make on the way past.

## Shape D — a test whose only assertion is a status. 43, all honest-ish.

No test in the suite asserts nothing at all, once locally defined helper
functions are followed (five look bare and delegate to `dvAssertHonest`,
`pseoAssertValid*` or `runOrderNumberRace`).

43 assert only `assertOk()` or `assertStatus()`. In most the status **is** the
claim — "refuses a file that is not a CSV" is a 422 and nothing else. Three
promise a side effect their body never checks, and are worth a line each by
whoever owns them:

- `ReviewAssignScreenTest:182` "refuses a destination product that does not
  exist **rather than writing it**" — never re-reads the review's `product_id`.
- `AdminReviewsTest:543` "bounds one bulk action **so a stuck loop cannot empty
  the table**" — never counts the table.
- `AdminCustomersTest:724` "will not let one bulk call **empty the table**" —
  the same.

None of the three can pass over a broken endpoint; they are weaker than their
names, not vacuous.
