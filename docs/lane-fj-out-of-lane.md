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
