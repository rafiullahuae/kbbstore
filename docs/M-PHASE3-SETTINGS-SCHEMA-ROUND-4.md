# Lane M4 · Store → Mail, the `secret` type, and the reader that answered a sentence

Round 4 of the per-module settings schema, and the last of the eight modules
round 1 named. Round 2 took five, round 3 took three and wrote the specification
for this one in its §5 — then declined it, in one sentence that is the whole
reason this round is written the way it is:

> Migrating it moves what the Mail `<select>` gets on a screen whose failure
> mode is **a shop that stops sending order email**.

Nothing here is inferred from a status column. Every claim about behaviour was
established by driving the code; every claim about a screen by driving Chromium
at 390px and 1280px; every mutation below was run and what it printed is quoted
verbatim, including the three that printed something other than what the comment
beside them first predicted.

---

## 0 · The headline, which is that there is no headline

The brief said the round's first job was to find out what happens **today** when
the mail password is read through each path — *"put a real value in and look for
it in every response"* — and that a live leak would take priority over the
migration.

**A real value was planted and it was looked for. It is not anywhere it should
not be.**

`tests/Feature/MailSecretSurfaceTest.php` plants `KBBM4SURFACE-p4ssw0rd-QQ71`
into the encrypted credential row through the screen's own save, points the
transport at a host that refuses so a genuine SMTP failure message is produced,
and then looks for the literal, the URL-encoded and the base64 spellings in:

| Where | Result |
| --- | --- |
| **every parameterless GET route this application answers, with an owner session** — 178 of them, admin console, storefront and public API together | not found |
| `MailSettings::all()` | not found (the key is absent entirely) |
| `MailSettings::lastTest()` — the stored outcome of the last test-send | not found |
| the `settings` table, read straight off the column | not found |
| `Setting::map()`, which `GET /admin-api/settings` returns wholesale | not found |
| `mail_deliveries` — the shop's own delivery log | not found |
| `storage/logs/laravel.log` after a failed send | not found |
| the test-send result that a failing transport produced | not found |
| `mail_credentials.config` past the model's cast | ciphertext |

**Run first against the parent revision, before a line of this round existed.**
That matters: the sweep is evidence about the code as it was, not about the code
after it was rearranged.

The three redactors that make this true — `MailTester::redact()`,
`MailLog::redact()` and `OrderMailer::redact()` — each strip the literal, the
URL-encoded and the base64 forms, because an SMTP AUTH failure echoes the
credential back and Symfony's transport exceptions quote the DSN, and both have
been seen carrying it. Reading those three and believing them is not the same
act as planting a value and looking, and the second is what was done.

The sweep is kept rather than thrown away. It has no list to extend: the next
endpoint added to this application is walked by it on the day it is added.

**One thing it did find**, and it found it in this round's own work — see §5.

---

## 1 · Task 1 — the `secret` type

Round 3 §5 set the minimum: *"a type `read()` refuses, `fields()` emits with no
`value`, `write()` routes elsewhere, plus `"-"` as a third write state."* All
four are built, and one thing is built that the specification did not ask for
and that the other four rest on.

### The thing that is not in the specification: the schema cannot read a secret

`App\Services\SecretStore` is `put()` and `has()` and **no getter**.
`ModuleSchema::write()` types its parameter as that interface. So the guarantee
is not "ModuleSchema does not call `get()`" — a rule that lives in somebody's
memory, which is the rule that produced the sixteen-field colour defect round 1
found, five copies of three lines with the fix in only one of them. The
guarantee is that the thing the schema is handed **has no method that returns a
stored value at any signature**, and widening it shows up in a diff as what it
is.

`MailCredentials` implements the interface and keeps its own `get()`, because
`MailConfigurator` has to build a transport out of the password. **The narrowing
is at the seam, not at the store.**

### The four, each with the mutation that removes it

| Contract | How | Mutation, and what it printed |
| --- | --- | --- |
| `read()` refuses | the key is **absent** from the map, not `''` | delete the arm → `LogicException: Module setting “api_key” is a secret; it is written to a SecretStore and never cast.` |
| `fields()` emits no value | no `value` key and no `default` key; `has_value` is a `(bool)` from its own parameter | delete the branch → `a secret was rendered with a value key / Failed asserting that true is false.` |
| `write()` routes elsewhere | `store: vault`, checked against the type **in both directions** | route it to `$settings->set()` → two cases red, `Undefined array key "api_key"` and `Failed asserting that false is true.` |
| `"-"` is a third write state | compared as a trimmed literal, **before** anything | drop the `trim()` → `a blank box wiped the stored credential / -'KBBM4-SECRET-CANARY-7f3a' +'   '` |

**Absent, not `''`.** An empty string is a value: a caller can carry it, put it
in a payload, log it, and eventually render it, and the day the store behind it
answers something other than `''` none of those call sites changes. A missing key
fails at the place somebody reaches for it, which is the only place a mistake
here can be fixed.

### `cast()` throws on a secret, and that is the sentinel's real defence

Every other arm of `cast()` normalises — it trims, it caps, it substitutes a
default for something it will not store. All three are wrong for a credential:
`  hunter2  ` may genuinely be the password, a cap silently stores a prefix that
will never authenticate, and `blank => 'default'` would turn the forget sentinel
into the shipped default.

That last one is the failure the brief named: *"the kind of sentinel that breaks
when a schema starts normalising values."* So `-` is compared **before** any
cast, and anything that routes a secret through `cast()` instead gets an
exception rather than a quietly different password.

Both halves were mutated. Routing the secret through `castText()` under this
module's policy leaves `-` intact — which is precisely why the throw exists
rather than a cast that happens to be safe today: **the next policy would not
leave it intact and nothing would say so.** The mutation that does bite is the
smaller one, dropping the `trim()`, and it turns three spaces into the SMTP
password while Store → Mail goes on saying a password is stored.

### The store/type cross-check, both ways

A `secret` that is not `store: vault` throws, and a non-secret that **is**
`vault` throws. The second half catches the likelier mistake: a `text` field
declared `store: vault` would be routed to the credential store by `write()` and
then emitted **with its value** by `fields()`, because `fields()` only withholds
a value from a `secret`. The two halves of the contract travel together or each
one is a way to lose the other.

A secret may not carry a `rule` either — the same closure round 2 shut on
colours and selects, for the same reason: the guarantee belongs to the type and
may not be handed to a module-local function.

---

## 2 · Task 2 — the reader that answered a sentence

Round 3's blocker, in its own words:

> `MailSettings::all()` returns the **label** where every other reader returns
> the stored key, and `canonicalTransport()` accepts key-or-label-or-empty.

### Why it did that, because it is a real requirement and not a bug

The console's `mailField()` prints each option string as **both** the value and
the visible text — `<option value="${o}">${o}</option>` — so the only way the
owner sees *"Use this server's mail (default)"* instead of the word `server` is
for that sentence to be the option string. And the only way the owner's saved
choice comes back **selected** is for the payload's `value` to be that same
sentence. `resources/views/admin/app.blade.php` belongs to another lane and was
not edited.

### The shape: the key is the contract, the label is derived where it is shown

- `MailSettings::all()` now answers the **stored key**, like every other reader
  on this schema, and it answers it by calling `transport()` — so `all()`,
  `get('mail_transport')` and `transport()` are the same string **by
  construction** rather than by agreement.
- `MailApiController::show()` is the **display boundary** and the only place in
  the application that turns a key into a sentence. It derives three things from
  the one schema declaration rather than from a second list: the `choice` type,
  the option labels in declared order, and the label of the stored key.
- `optionsFor()` is gone. The option sets were named three times
  (`TRANSPORT_LABELS`, `TRANSPORTS`, `optionsFor()`); the screen now cannot
  offer an option the cast would refuse.

**Nothing stored moved.** The recorded corpus compares the raw settings row on
all 231 calls, and every `mail_transport` row is byte-identical — `server`,
`smtp`, `log`, exactly as before, from every one of the thirteen spellings
driven at it including all three labels.

### Proved at the payload level

`tests/Fixtures/module-screen-payloads.json` gained `mail`, recorded off the
parent revision, and `ModuleScreenPayloadTest` compares it **outright** — the
whole `fields` list, every `value`, every `has_value`, every `options` array, in
order, plus `configured`, `missing`, `transport` and `last_test`. The screen has
no `tabs` key, so there is no tab walk to be lenient: one moved label, one
reordered option, one `value` that came back as a key, and it fails. It passes.

The one assertion that had to move is named rather than buried.
`ServerMailDefaultTest > it reads a key written by an older release as the same
choice` asserted `MailSettings::get('mail_transport')` was the sentence. **The
requirement is unchanged and is still pinned — at the boundary that has it:**
the payload. The same test now also pins that the three readers agree, which is
the thing that could not be asserted before.

---

## 3 · Task 3 — the sort, policy against real rules

Done the way rounds 2 and 3 did it.

**Policy** — expressible on the axes `ModuleSchema::POLICY_KEYS` already
carries, so it moved onto the shared cast:

| Axis | Value | Why |
| --- | --- | --- |
| `max` | `MAX_LENGTHS` per field for the seven it names; `UNCAPPED` for the other nine | see below |
| `blank` | `keep` | an emptied box here means "there is no such address / no such wording", and every reader downstream falls back for itself. A shipped default would be the shop inventing a support number. |
| `invalid` | `default`, which is `''` for every field | what `save()` has always stored for a non-scalar |
| `markup` | `keep` | these strings are printed into an email template that escapes them; stripping would silently rewrite a signature containing a `<` |

**`UNCAPPED` is `PHP_INT_MAX`, and the silliness is the point.** `max` is a cut,
and every value on that axis is a length; there is no value meaning *"this class
does not cap this field"*, which is exactly what `save()` has always done with
the nine keys `MAX_LENGTHS` does not name. Writing `5000` instead would have
been a **new bound on nine live fields**, which rule 1 does not allow — and the
recorded corpus would have caught it, because it drives a 600-character value at
every text box. A cap this class does not have is better stated as an
unreachable number with a comment beside it than as a plausible one a reader
would take for a decision. What bounds those nine in practice is
`MailApiController::$checks`, and that file belongs to another lane, which is
the reason `MAX_LENGTHS` exists for the other seven in the first place.

**A real rule** — a constraint peculiar to one setting that would be wrong
applied to any other, kept **as** a rule:

| Rule | Where | What it does |
| --- | --- | --- |
| `MailSettings::addressOrDrop()` | named on `mail_merchant_address` and `mail_reply_to` by `schema()` | trim, cap, then a format check; a malformed address is **dropped**, not truncated and not substituted, because the previous address is a working mailbox and "not an address" is a header the transport refuses. It replaces the type arm, so it does the trim and cap itself — in that order, because a 260-character address is cut to 255 and **then** found to be malformed, which is what `save()` has always done. |

`mail_transport`'s label reduction is **not** a rule, and could not be: a rule
replaces the type arm, and round 2 deliberately closed rules to `select` so that
*"a select stores one of its own options"* stays a guarantee of the type. The
sentence is reduced at the screen boundary in `save()` instead, before the
schema sees it — which is what keeps the settings row from ever holding a
sentence, and what lets a label be reworded without invalidating a single
install's saved choice.

---

## 4 · Rule 1, proved rather than asserted

**231 recorded round trips. 211 byte-identical on every reader. 20 moved, in
two clusters, each exempted narrower than a field with its defect named.**

The instrument is a **third** fixture and had to be. Rounds 1 and 3 recorded
pure functions — `cast($key, $raw)` and `normalise($key, $value)` both answer
from their arguments alone. `MailSettings` answers nothing that way: `save()`
writes, to `settings` for fifteen keys and to the encrypted `mail_credentials`
row for the sixteenth, and `all()` reads back through a cache. So the round trip
is what is recorded: plant, save, read back through all three readers that
disagree, one line per call, into
`tests/Fixtures/module-mail-baseline.txt`, off the parent revision.

`all=`, `raw=` and `has=` are recorded **separately on every line**, and that is
the whole design. `raw` is read straight off the table, past every cache, so
*"the stored bytes did not change"* is a measurement rather than an inference —
and it is the assertion the round rests on, because a migration that rewrote a
stored transport key is the shape that stops a shop sending.

### The two exemptions

**1 · `mail_transport`, `all=` only — 13 lines.** This is Task 2: the reader
handed back a display sentence where every other reader hands back the stored
key. `raw=` and `has=` are compared on the same lines, so the exemption cannot
cover a stored value that moved.

**2 · `mail_encryption`, `raw=` only, and only to one of the field's own three
options — 7 lines.** The defect: `save()` stored whatever was posted, so the
settings row could hold `SMTP`, `0`, `nonsense` or `smtp` as an encryption —
rule 5 of the project notes in as many words, *"a select stores one of its own
options or the default"*. It was never visible on the shop, because `all()`
re-derived to `ssl` on the way out and `MailConfigurator` reads `all()`. What was
wrong was the row, and a row that is a lie about the shop is one reader away
from being handed to Symfony as a DSN scheme. **`all=` is compared on those
seven lines and is `'ssl'` before and `'ssl'` after, on every one of them**,
which is the argument that the fix moved a row nothing read and nothing else.

The test also asserts the exemptions **fired** — `count($moved) === 20`. An
exemption that covers nothing is a claim nobody is checking any more.

### And the two older fixtures are untouched

`ModuleSchemaEquivalenceTest`'s 4,653 calls still pass with their original 32
exemptions, and `ModuleSettingsEquivalenceTest`'s 345 with none. The new type,
the new store and the new axis value reach only the module that declares them.

---

## 5 · What this round's own instruments found in this round's own work

Two, both test-shaped, both found by running something rather than reading it.
They are recorded here because a round that only reports what it meant to find
is a round reporting its intentions.

**1 · A fixture that committed passwords to git.** The first version of the
recorder wrote `json_encode($input)` for every type, so the password corpus went
into the tracked fixture verbatim. They were invented strings and nothing was
disclosed — but the shape is *a recorder that commits whatever it is driven
with*, and the day somebody re-records it against a value copied off a real
screen the fix is rewriting history. Found by `MailSecretSurfaceTest`, a test
written to check this could not happen, run against a file where it had.
`SettingsCorpus::mailInput()` now writes `<secret:len=10:e5fe9d7d>` — length plus
a short digest, which keeps one call distinguishable from another without
carrying a byte of the value.

**2 · Two assertions that could not fail.** `expect($x)->not->toHaveKey('value',
'…message…')` reads as *"does not have key `value` holding that sentence"*,
because Pest's `toHaveKey` takes a key and an expected **value** — so it is true
of a payload carrying the credential. It stayed green with the branch it was
guarding deleted, and mutation M3 is what exposed it. The same mistake in
`->not->toContain($needle, $message)` was caught by this repo's own
`ExpectationsThatCannotFailTest` on the full run, which named the file and line
and told me what to write instead.

Both are why the mutations in this round were **run** rather than described. A
mutation that does not go red is the only thing that finds a test that cannot
fail.

---

## 6 · Mutations, actually run, with what each printed

Eleven. Each was applied, the suite run, the output copied, the change reverted.
Three printed something other than the comment's first prediction and the
comments were corrected to what really happened rather than the other way round.

**1 · `SecretStore` gains `get(string $key): string`.** Red before the assertion,
which is louder than the assertion:

    Pest\Exceptions\FatalException
    Class M4Vault contains 1 abstract method and must therefore be declared
    abstract or implement the remaining methods (App\Services\SecretStore::get)

**2 · `read()`'s `if ($f['type'] === 'secret') continue;` deleted.**

    FAILED … it does not…   LogicException
    Module setting “api_key” is a secret; it is written to a SecretStore and never cast.
    at app/Services/ModuleSchema.php:884

The backstop fires before the absence assertion, and both are wanted.

**3 · `fields()`'s secret branch removed.**

    a secret was rendered with a value key
    Failed asserting that true is false.

First run, this mutation passed — and that is what exposed the `toHaveKey`
defect in §5.

**4 · `write()`'s secret arm writes to `settings` instead of the vault.** Two
cases red:

    FAILED … it writes a…   ErrorException
    Undefined array key "api_key"

    FAILED … it keeps the three write sta…
    Failed asserting that false is true.

**5a · `cast()`'s secret throw replaced with `castText()`, and `write()` routed
through it.** One case red:

    FAILED … it refuses to cast a secret,…
    Exception "LogicException" not thrown.

The sentinel case stayed **green**, correctly and instructively: under this
module's policy `castText('-')` is `'-'`, so the fold costs nothing you can see —
which is the argument for the throw rather than for a cast that happens to be
safe. Same shape as round 3's `#ABC`.

**5b · the `trim()` dropped from `write()`'s secret arm** — the smallest
plausible normalising change:

    a blank box wiped the stored credential
    Failed asserting that two strings are identical.
    -'KBBM4-SECRET-CANARY-7f3a'
    +'   '

**6 · the `$vault === null` guard deleted.**

    Failed asserting that an instance of class Error is an instance of class LogicException.

PHP's own "Call to a member function put() on null" — red either way; the guard
is what makes it a sentence naming the schema and the key instead of a stack
trace on Store → Mail.

**7 · `field()`'s secret/vault cross-check deleted.**

    Exception "InvalidArgumentException" not thrown.

**8 · `canonicalTransport()` dropped from `MailSettings::save()`.**

    -    1 => 'smtp   -> EsmtpTransport       send=TransportException logged=smtp/sending',
    -    2 => 'log    -> LogTransport         send=sent               logged=log/sent',
    +    1 => 'smtp   -> ServerMailTransport  send=sent               logged=server/sent',
    +    2 => 'log    -> ServerMailTransport  send=sent               logged=server/sent',

**This is the failure round 3 said it was afraid of, made to happen.** Both
non-default choices fall back to `server`, the shop still sends, and no error
appears anywhere.

First run, this mutation **passed** — because the test was posting the canonical
key, and the console posts the sentence. The test was rewritten to post what the
screen posts, which is the only reason it bites.

**9 · `show()`'s `is_array($options)` arm deleted** — the label derivation
removed. Two cases of `ServerMailDefaultTest` red and `ModuleScreenPayloadTest`
red on the recorded `mail` payload:

    Failed asserting that two strings are identical.
    -'Use this server's mail (default)'
    +'server'

**10 · `write()`'s secret arm stops calling the vault.**

    Failed asserting that null is identical to 'hunter2-not-a-real-password'.

and three cases of `MailSecretsTest` with it, including *"it treats a blank
password on save as unchanged rather than a wipe"*.

**11 · `SettingsCorpus::mailInput()` quotes a secret again.**

    tests/Fixtures/module-mail-baseline.txt carries a credential
    Failed asserting that true is false.

---

## 7 · The one assertion that speaks to the failure mode

`tests/Feature/MailDeliveryUnchangedTest.php` is the file the brief asked for:
*"send a real email in a test and assert it still arrives with the same
transport, before and after."*

It configures each of the three transports **the way the screen does — posting
the sentence, not the key** — and hands a real message to the real mailer. Not
`Mail::fake()`, which records the Mailable and never builds a transport, which
is the thing under test. Then it reads back the transport class the mail manager
really built, whether the send completed, and what the shop's **own** delivery
log (`mail_deliveries`, the table behind Store → Mail → Sent mail) wrote about
it — whose `transport` column is written from `MailSettings::transport()`, one of
the two readers this round made agree.

**Measured, and identical on both revisions:**

    server -> ServerMailTransport  send=sent               logged=server/sent
    smtp   -> EsmtpTransport       send=TransportException logged=smtp/sending
    log    -> LogTransport         send=sent               logged=log/sent

The SMTP row throws at the socket on purpose: the host resolves to nothing, and
a `log` or `server` transport cannot produce that error, so the exception **is**
the evidence that ESMTP was built. The second case asserts the credential
reaches the transport config, because a save that reported success and stored
the password nowhere leaves a shop authenticating with an empty string and
finding out hours later.

**The file is written to run on both revisions**: it touches nothing this round
added and never reads `all()['mail_transport']`, which is the one answer the
round deliberately changes. So the before-and-after is a measurement — the same
file, run against the stashed parent tree and against this one, `2 passed (6
assertions)` both times, with the three lines above identical.

---

## 8 · Admin paths

| What | Where |
| --- | --- |
| Every setting whose reader, writer and cast moved onto the shared schema | **Store → Mail** |
| The dropdown whose stored value is now a key and whose label is derived at the screen | **Store → Mail → How email leaves this store** (*How this store sends email*) |
| The encryption box whose row is now one of its own three options | **Store → Mail → Mail server** (*Encryption*) |
| The password box that is now a schema `secret`, and the `-` that forgets it | **Store → Mail → Mail server** (*Password*) |
| The two addresses whose format check is now a declared rule | **Store → Mail → Who the message comes from** (*New-order alerts to*) and **Store → Mail → Other settings** (*Reply-To address*) |
| The seven caps that are now the schema's `max` axis | **Store → Mail → What customers see at the foot** and **Other settings** |

**No control was added, removed, renamed or moved.** Every path above is where it
already was, and no setting changed the value it ships at.

---

## 9 · Measured, in Chromium, before and after

`tests/browser/lane-m4-mail-screen.mjs` produces both tables without being edited
between them — point it at a checkout of either revision and change `KBB_M4_TAG`.
It reports the key each control edits **in document order**, and — the
measurement round 3's script did not need and this round does — **the chosen
option of every `<select>` beside its full option list**, because a dropdown that
silently paints on its first entry instead of the owner's saved choice is exactly
the failure a control count cannot see.

Two shop states were captured, because the virgin one cannot show a saved choice:

**A shop that has saved nothing:**

| | 390px | 1280px |
| --- | --- | --- |
| Controls | 17 → 17 | 17 → 17 |
| `scrollWidth` / `innerWidth` | 390 / 390 | 1280 / 1280 |
| Transport `<select>` | *Use this server's mail (default)*, index 0 → same | same |
| Encryption `<select>` | `ssl`, index 0 → same | same |
| Password box | `type=password`, placeholder *Not set*, **no `value` attribute** → same | same |

**A shop set to the log transport, with a password stored and nine boxes
filled:**

| | 390px | 1280px |
| --- | --- | --- |
| Controls | 17 → 17 | 17 → 17 |
| `scrollWidth` / `innerWidth` | 390 / 390 | 1280 / 1280 |
| Transport `<select>` | *Send nothing — write to the log (for testing only)*, **index 2** → same | same |
| Encryption `<select>` | `tls`, **index 1** → same | same |
| Password box | placeholder *Stored — leave blank to keep it*, **no `value` attribute** → same | same |

Per band, identical too, at both widths and in both states — *How email leaves
this store* 1 · *Mail server* 6 · *Who the message comes from* 3 · *What
customers see at the foot* 4 · *Other settings* 3. And every control key matched
in order.

**`document.documentElement.scrollWidth` equals `innerWidth` on every capture** —
390 and 1280, two states, before and after. No horizontal overflow anywhere.

**All four screenshot pairs are byte-identical between the two revisions** —
same md5, same file size:

    a675ee69c0d05b7ac175de0624a6a039  m4-after-mail-1280.png / m4-before-mail-1280.png
    8d4d305a565228b4ede6f72b45ce3fbe  m4-after-mail-390.png  / m4-before-mail-390.png
    1a2aa25a44a25eb5a2228743f5e12aa8  m4-after-set-mail-1280.png / m4-before-set-mail-1280.png
    8c898c3a37741d7aaf4e25b50c090380  m4-after-set-mail-390.png  / m4-before-set-mail-390.png

Round 3 had to explain away 33 pixels of a live preview. There is nothing to
explain away here: four pairs, four matching digests.

---

## 10 · Screenshots

`docs/m-module-shots/m4-*`, captured in real Chromium against a seeded SQLite
database served through this worktree.

| File | What it shows |
| --- | --- |
| `m4-before-mail-390.png` / `m4-after-mail-390.png` | Store → Mail on a phone, on a shop that has saved nothing. Byte-identical, which is the point. |
| `m4-before-mail-1280.png` / `m4-after-mail-1280.png` | the same at desktop width. |
| `m4-before-set-mail-390.png` / `m4-after-set-mail-390.png` | Store → Mail on a shop set to the log transport with a password stored — the state where the label derivation is load-bearing and where the password box reads *Stored — leave blank to keep it*. |
| `m4-before-set-mail-1280.png` / `m4-after-set-mail-1280.png` | the same at desktop width. |

---

## 11 · Out of lane — named, not fixed

- **`mail_reply_to` is still dropped in silence when it is malformed.**
  `MailApiController::$checks` has an `email` rule for `mail_merchant_address`,
  so the screen reports that one; it has no entry for `mail_reply_to`, so a
  mistyped Reply-To saves "successfully" and keeps the old value.
  `ModuleSchema::write()` now **reports** the refusal — `save()` drops the
  report, which is exactly what it has always done — so surfacing it is one line
  plus an error on the screen. That is a screen change, and `$checks` is in a
  controller this lane does not own.
- **Store → Mail is still not on `ModuleFrameworkGuardTest`.** That guard
  requires a `TABS` constant, and this screen's display order lives in
  `MAIL_SECTIONS` in `resources/views/admin/app.blade.php` — another lane's
  file. `MailScreenDesignTest` already pins the same guarantee for this screen
  (every SCHEMA key gets a control, every placed key exists in SCHEMA), so the
  cover is there; the line on the guard is not.
- **Nine of the sixteen fields have no cap in this class** — `UNCAPPED`, §3.
  Their only bound is `MailApiController::$checks`. Giving them real caps is a
  behaviour change on live fields and belongs in a round that says so.
- **`MailSettings::SCHEMA` is still positional `[type, label, help]`**, with
  `help` in the slot every other schema in this application uses for the shipped
  default. It was not rewritten because six tests and one controller read it,
  three of them destructuring it, and two of those files belong to other lanes.
  `MailSettings::schema()` widens it in one place and says which slot is which;
  handing the constant to `ModuleSchema::field()` positionally would read four
  paragraphs of help as the default value of a box.

---

## 12 · Suite

    mysql -u root -e "CREATE DATABASE IF NOT EXISTS kbb_wp_m4;"
    KBB_WP_DB=kbb_wp_m4 vendor/bin/pest --compact

**5,912 passed · 26 skipped · 0 failed**, 50,287 assertions, 343.28s. Exit 0.

`KBB_TEST_DB` is left unset, which is what CLAUDE.md prescribes for the default
suite — there it is a FILE PATH, not a database name, and `tests/bootstrap.php`
already gives an unattended run a file of its own. `KBB_WP_DB` is named per lane
and is load-bearing rather than decorative: the WordPress-exporter harness builds
its own MySQL database and drops every table it uses, and two lanes running the
suite at once tear it down under each other.

**One failure on the way, and it was this round's own and is fixed rather than
excused.** The first full run was `1 failed, 26 skipped, 5911 passed`:

    FAILED  Tests\Feature\ExpectationsThatCannotFailTest > it gives no variad…
    tests/Feature/ModuleSecretTypeTest.php:248  ->not->toContain() with 2
    arguments — the second is a NEEDLE, not a message, so this expectation
    cannot fail.

See §5. The repo's own guard named the file, the line and what to write instead.

No package migration is needed for this round: no route was added, so no
compiled route cache has to be cleared.
