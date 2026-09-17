# Lane FJ — changes owed to files this lane may not edit

CLAUDE.md and this lane's brief forbid editing `routes/web.php`,
`KBB-Master-Plan.md`, `KBB-Progress-Dashboard.html`,
`resources/views/admin/app.blade.php` and `bootstrap/app.php`. Everything below
is written out as an anchor and an exact replacement for the integrator to
apply. Nothing here is required for the lane's code to work — it is all
documentation that this lane's changes have made untrue.

## 1. `resources/views/admin/app.blade.php` — the Quiz Leads screen's header

**Why.** The comment states that `POST /api/quiz` validates `concerns` as
`'nullable'` with no type, no length and no content rule. That was true and is
now not: `Api\QuizController::validateCapture()` validates it as
`nullable|array|max:20` with `concerns.* => string|max:80`. The escaping the
paragraph argues for is unchanged and still correct — `sesc()` is still what
the screen must do, because tightening a rule is not the same as trusting the
value. Only the sentence describing the endpoint is stale.

It also no longer describes `recommended` accurately: that column is written
now (it was always null before), so the cell the paragraph names as unescaped
finally has something in it.

**Anchor** (lines ~13290–13296, inside the `Quiz Leads screen` comment block):

```
     THE HOLE. `concerns` and `recommended` were concatenated into this table's
     HTML with no sesc(), while every other cell on the same row was escaped.
     POST /api/quiz is public, unauthenticated, and validates `concerns` as
     'nullable' — no type, no length, no content rule — so the value is whatever
     an anonymous caller posts. That is stored cross-site scripting executing in
     the owner's authenticated admin session, on the one screen whose entire
     purpose is to be opened and read. Both cells go through sesc() now.
```

**Exact replacement:**

```
     THE HOLE. `concerns` and `recommended` were concatenated into this table's
     HTML with no sesc(), while every other cell on the same row was escaped.
     POST /api/quiz is public and unauthenticated, and validated `concerns` as
     'nullable' — no type, no length, no content rule — so the value was
     whatever an anonymous caller posted. That is stored cross-site scripting
     executing in the owner's authenticated admin session, on the one screen
     whose entire purpose is to be opened and read. Both cells go through sesc()
     now.

     THE RULE IS TIGHTER AND THE ESCAPING STILL MATTERS (Lane FJ). `concerns` is
     validated as nullable|array|max:20 with each entry string|max:80, and
     `recommended` is reduced to a routine name and a list of step names before
     it is stored. Neither is a reason to relax this cell: a tightened rule
     narrows what an anonymous caller can post, and 80 characters is plenty of
     room for an <img onerror>. `recommended` is also no longer always empty —
     store() writes the column now, so this is the first release in which that
     cell renders anything at all.
```

## 2. `routes/web.php` — nothing owed

No route was added or changed by this lane, so no `clear_caches_*` migration is
needed either. `Api\QuizController` and `Admin\InvoiceController` are reached
through routes that already exist.

## 3. `App\Support\AdminCapabilities::RULES` — nothing owed

No new admin path. `PUT /admin-api/quiz-leads/*` and `GET
/admin-api/quiz-leads` are already listed, writes before reads.

---

## 4. Findings outside this lane, reported not fixed

### 4a. `expect(...)->not->toContain($needle, $message)` asserts nothing

`Expectation::toContain()` is VARIADIC, so what reads as a failure message is a
second NEEDLE, and Pest's `not` passes as soon as the positive expectation fails
for any reason — including "the body does not contain the string I passed as a
message". Measured directly:

```php
$raw = '{"a":1,"probe":"sk_live_CANARY_stripe_secret_key_value"}';
expect($raw)->not->toContain('sk_live_CANARY_stripe_secret_key_value', '/api/settings'); // PASSES
```

Lane FJ repaired the two occurrences inside its own file
(`tests/Feature/ApiSecurityTest.php`, both gateway-secret sweeps, which were
passing over a response carrying the canary) and wrote its own quiz-lead sweep
the safe way. `tests/Feature/StorefrontImagesAndHeadingsTest.php` around line
199 already carries a note about the same trap, found independently.

These are in other lanes' files and are left for their owners. Each is a guard
that currently asserts nothing:

| File | What it believes it is guarding |
|---|---|
| `tests/Feature/MailDeliveryLogTest.php` | that no column of the delivery log carried a live token |
| `tests/Feature/SocialMetaListingPagesTest.php` | that no secret leaked into a page's `<head>` |
| `tests/Feature/CheckoutHiddenRowsTest.php` (line ~496) | that two Totals are not on screen at once |
| `tests/Feature/PriceDisplayTruthTest.php` (line ~406) | that a whole-dirham product grew no decimals |
| `tests/Feature/BilingualFoundationTest.php` (line ~1055) | that a forbidden column is not translated |
| `tests/Feature/AdminImportScreenTest.php` (line ~233) | that a route takes no parameter |

The repair is mechanical: `expect(str_contains($haystack, $needle))->toBeFalse($message)`.

### 4b. The Journal renders `<html lang="en">` on its Arabic URL

`resources/views/store/blog.blade.php` opens with a hard-coded
`<html lang="en">` inside `@verbatim`. Fetched: `/ar/skincare-guide/` serves
Arabic chrome under `lang="en"` and no `dir`. It is a standalone document that
does not extend `layouts/store.blade.php`, so it never picked up
`Locale::htmlLang()` / `Locale::direction()` the way every other page did.

Not fixed here: this lane's brief covers the tag filter on that page, and the
page's language attributes belong with whoever owns the bilingual foundation
work. The invoice layout got exactly this treatment in Part 3 and
`resources/views/invoices/document.blade.php` is the worked example.

### 4c. The quiz's consent record is asserted by the client

`skin-quiz.blade.php` sets `payload.consent = true` unconditionally in
`ensureLead()`. There is no consent checkbox anywhere on the form, so
`quiz_submissions.consent` and `consent_at` record an assertion made by the
page, not an affirmative act by the shopper. Lane FJ did not add a checkbox:
that changes the funnel and the copy and is the owner's decision. See the
report for what the form's wording does and does not cover.

### 4d. The contact step promises an email the shop does not send

"We'll save your results & email your plan." Nothing in `app/Mail` or
`app/Services/Mail` references `QuizSubmission` or the quiz at all, so no plan
is emailed. Reported, not fixed — writing that mailer is a feature, not a
correction.
