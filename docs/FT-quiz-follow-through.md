# FT · the quiz's follow-through — what shipped, what is owed, and two questions for the owner

Lane FT closes the two ends the skin quiz left open: the hand-off into Lane FM's
**Build my routine**, and the email the contact step has always promised and the
shop has never sent. This file carries the parts that are not code — the blocks
owed to files this lane may not edit, and the decisions that belong to the owner
rather than to a lane.

---

## 1 · Blocks owed to files this lane may not edit

**None.** Stated explicitly, because "no block" and "the block was forgotten"
look identical in a hand-back.

| File | Owed | Why not |
| --- | --- | --- |
| `routes/web.php` | nothing | No route is added or changed. `POST /api/quiz` and `GET /skin-quiz` are already registered, and `/routines` + `/routines/{concern}` came in with Lane FM's `require` line, which is **already merged** at the commit this lane branched from — verified by fetching, not by reading: `Route::has('routines.show')` is true and `/routines` answers 404 because the module is off, not because the route is missing. |
| `resources/views/admin/app.blade.php` | nothing | No admin screen changes. The plan email appears on Store → Sent mail on its own, because it labels itself `quiz.plan` through `MailLog::labelNext()` and that screen renders whatever kinds it finds. |
| `KBB-Master-Plan.md`, `KBB-Progress-Dashboard.html` | nothing | Noted in the PR body instead, per CLAUDE.md. |
| `bootstrap/app.php` | nothing | Untouched. |

**The package still needs a cache migration**, and it ships with one:
`database/migrations/2026_11_19_000000_clear_caches_quiz_follow_through.php`.
Not for routes — there are none — but for **views**:
`resources/views/store/skin-quiz.blade.php` is edited rather than new, compiled
Blade is a filemtime comparison, and an update package on this host is an unzip.
A stale compiled quiz would keep serving the page *without* the routine hand-off
while Store → Modules said Build my routine was on, which is the same
"indistinguishable from the switch being off" trap Lane FM's own migration
describes, arriving from the other end.

---

## 2 · Two questions for the owner. Neither is implemented.

### 2a · The quiz records a consent the shopper never gave

**In two sentences, for relay.** The quiz has no consent checkbox anywhere on
it: `ensureLead()` in `skin-quiz.blade.php` sets `payload.consent = true`
unconditionally, so `quiz_submissions.consent` and `consent_at` record an
assertion the *page* made rather than an affirmative act by the shopper. Adding
a real checkbox is the owner's call because it changes the funnel — a box that
must be ticked is one more thing between a shopper and their results, and an
unticked box means deciding on the spot whether they still get the plan.

**The detail he will want.**

- What the form says today, in full, is: *"Where do we send your routine? We'll
  save your results & email your plan. No spam, ever."* Under the fields it
  promises *"10% off your first order"* and *"Free expert help"*. That is a
  statement of what the shop will do. It is not a request for permission, and
  nothing on the page asks for one.
- What the column is used for matters more than what it is called. `consent`
  and `consent_at` are read by the owner's Quiz Leads screen and are the only
  record in the shop that this person agreed to be contacted. As it stands
  every lead reads "consented", including one captured by an anonymous caller
  posting to the public endpoint directly, so the column cannot distinguish
  anybody and answers no question worth asking.
- **Three shapes, and they are genuinely different.** (i) A required tick —
  strongest record, biggest funnel cost, and it needs an answer to "what does
  the shopper see if they do not tick it". (ii) An optional tick that gates
  *marketing* only, with the plan email sent regardless because it is the thing
  they asked for. (iii) Leave the flow alone and stop writing `consent = true`
  from the page, which costs the funnel nothing and makes the column honest by
  emptying it.
- **This lane's own change does not depend on the answer.** The plan email is
  transactional — it is the thing the shopper pressed the button to get, and it
  says so in its own last paragraph ("this is that one message; the address has
  not been added to any list"). It is not marketing and does not rest on this
  consent record. Anything that *does* start marketing to these addresses would.

### 2b · The allergy step collects health data the shop deliberately does not keep

**In two sentences, for relay.** The allergy step collects an allergen list and
a free-text box whose own placeholder invites "allergies, pregnancy, current
products", and Lane FJ deliberately stored none of it: it is health data, the
step's wording only promises the quiz will steer around ingredients *while it is
on screen*, and the form never asks to keep it. Keeping any of it would be a new
category of record about a person and needs the owner to say so on the form
first — so the question is whether he wants it, and if so, which part and for
what.

**The detail he will want.**

- **What is thrown away today.** `answers.allergies` (six fixed options —
  fragrance, essential oils, drying alcohol, nut oils, lanolin, none) and
  `answers.allergyNote` (free text). Both are posted by the page, both are read
  by `recommend()` in the browser, and both stop at the endpoint. There is no
  column for either.
- **The two halves are not equally sensitive, and that is the useful part of
  the question.** The six fixed allergens are close to an ingredient
  preference; a shop could hold them the way it holds "sensitive skin", with a
  line on the form saying so. The free-text box is where somebody types
  "pregnant" or names a prescription, and it cannot be bounded in advance —
  whatever rule is written, the box will one day hold a medical fact about a
  named person with a phone number beside it.
- **What it would buy.** The honest answer for the *expert* case: somebody
  reviewing a routine by hand would plainly like to know. Note that the expert
  flow already has a box for exactly that — the results screen's own message
  field, which the shopper fills in knowingly, addressed to a person, and which
  *is* stored (`expert_message`). So the case for storing the quiz's box is
  weaker than it first looks: the information reaches the expert already, by a
  route the shopper chose.
- **The cost is not only privacy.** `/api/*` is unauthenticated and
  `quiz_submissions` already carries a name, a WhatsApp number and an email;
  `ApiSecurityTest` sweeps every registered `/api` GET with a lead in the table
  for exactly that reason. A health column would raise what a future leak on
  that surface costs, from "contact details" to "contact details and a medical
  note".
- **If he wants it, the order of work is fixed:** the form says what is kept and
  why, *then* a column exists, *then* the endpoint writes it. Not the other way
  round.
- **Do not weaken the test.** `ApiSecurityTest`'s *"stores nothing the contact
  form did not ask permission to keep"* is the line, and this lane added a
  second lock on the same door from the mail side — `QuizFollowThroughTest`'s
  *"puts none of the allergy answers in the email either"*, because an email is
  a copy of the record that leaves the building. A change here should turn both
  red and be argued with, not routed around.

---

## 3 · A third promise on the same card, reported and not touched

The contact step's trust row promises **"10% off your first order"**. Nothing in
this application issues, shows or emails such a code: there is no first-order
coupon, no welcome code in `coupons`, and the plan email this lane added
deliberately does not invent one. It is also the only string in that row that
does not go through `t()`, so an Arabic shopper reads it in English.

Not changed here, and the reason is the reason this whole lane exists: a lane
should not quietly rewrite a promise the owner may be keeping by hand. **The
question is one line: is there a 10% first-order code, and if so what is it?**
If there is, it belongs in the plan email where it can be used; if there is not,
the line should come off the card, because it is the same defect as the email
that was never sent.

---

## 4 · What changed, in one place

- `app/Support/QuizRoutineLink.php` — new. The quiz's English concern ⇒ that
  concern's routine URL, or `null`. Null is the shipped answer.
- `resources/views/store/skin-quiz.blade.php` — emits that table only when it is
  non-null, and the results screen draws one link from it.
- `app/Http/Controllers/Api/QuizController.php` — sends the plan after the
  response has gone; tightens `email` to `Rules\StorefrontEmail`.
- `app/Mail/QuizPlanEmail.php`, `resources/views/emails/quiz-plan*.blade.php` —
  new. The stored routine names and step names, and nothing else.
- `app/Services/Translation/InterfaceStrings.php` — two quiz keys, eleven email
  keys.
- `tests/Feature/QuizFollowThroughTest.php` — new.
- `tests/Support/EnglishRenderWalk.php` — `BASE_COMMIT` moved forward; the note
  above it records the diff and why it is inert.
- `database/migrations/2026_11_19_000000_clear_caches_quiz_follow_through.php` —
  new.
