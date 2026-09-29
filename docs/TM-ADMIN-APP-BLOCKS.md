# Lane TM · the four edits the integrator applies

`resources/views/admin/app.blade.php` is the integrator's file and this lane may
not edit it, so **Store → Gateway webhooks** is delivered as anchor/replacement
blocks in the shape `docs/T1B-ADMIN-APP-BLOCKS.md` established. Every anchor
below was verified to occur **exactly once** in the console on
`claude/kind-mayer-rpqesv` at `73aa51d`.

**All four are one commit.** Applying three of them leaves a sidebar row that
opens the dashboard under its own heading, which is the silent half-wired
failure `AdminNavAndIdsTest` exists to catch.

---

## What is red until they are applied, and what is green after

Measured in this lane's worktree, both ways, on the same tree.

| | assertions red | full suite |
|---|---|---|
| **Before** (as this branch ships) | **2** — see below | not run; the two are static file checks |
| **After** (all four applied) | **0** | **7,712 passed, 26 skipped, 0 failed**, 578s |

The two red before are both the *finished-state* pin CLAUDE.md prescribes —
`=== 1`, never `->not->toContain` — so each goes green the moment the include
lands and stays a real guard afterwards:

```
EverythingIsMountedOnceTest > it includes every admin console partial exactly once
    tamara-connection-screen           is included 0 times

TamaraConsoleReachTest > it includes the gateway-webhooks screen exactly once
    Failed asserting that 0 is identical to 1.
```

Applying blocks 1–3 and **not** block 4 gives a third:

```
TranslationConsoleTest > it keeps the handover document and the applied console in step
    block 3 is not applied to app.blade.php exactly once
```

which is that document's own rule — when `LATE_RENDERED` moves, its record moves
with it. Lane FIN2 hit the same case on 29 September for `'setap'`.

---

## Block 1 · draw the screen

Anchor (occurs once):

```
@include('admin.partials.security-screen')
```

Replacement:

```
@include('admin.partials.security-screen')

{{-- Store → Gateway webhooks (Lane TM). The webhook registration, basket limits
     and recovery sweep behind Tamara's five admin endpoints, and Tabby's two —
     all seven of which shipped live, capability-mapped, migration-and-all, and
     called by NOTHING in this console. Tamara's merchant portal has no webhook
     screen, so until this landed the registration that carries every decline
     and expiry could not be made from anywhere at all.

     It registers its own sidebar entry inside the Store group and wraps
     window.go, exactly as the screens above do. It also appends ONE button to
     the Tamara card on Store → Payments, from the outside and idempotently, so
     the owner finds it where he is already standing.

     Applying it changes nothing on the live shop: it adds no setting, moves no
     default, and writes nothing anywhere until a button on it is pressed. --}}
@include('admin.partials.tamara-connection-screen')
```

## Block 2 · give the screen a breadcrumb and a heading

Without this, `?go=paygw` and `#paygw` open the dashboard and the screen has no
shareable URL. `AdminNavAndIdsTest` also compares this row against what the
partial writes for itself, so the two strings must be exactly these.

Anchor (occurs once):

```
'security':['Store','Security'],
```

Replacement:

```
'security':['Store','Security'],'paygw':['Store','Gateway webhooks'],
```

## Block 3 · arm the deep-link replay

`LATE_RENDERED` is the set of ids `go()` would otherwise answer with the
dashboard, because the partial that owns them is appended after the console's
own script has run. The condition the four originals each state — *the partial
wraps `window.go` and calls `render()` before it awaits anything* — is met here:
`tamara-connection-screen.blade.php` renders synchronously and then loads.

Anchor (occurs once):

```
'setap','cache','cartpage','checkoutpage','routines','security','sitelayout','slimfooter']);
```

Replacement:

```
'setap','cache','cartpage','checkoutpage','routines','security','paygw','sitelayout','slimfooter']);
```

## Block 4 · keep the other handover document in step

**In `docs/T1B-ADMIN-APP-BLOCKS.md`, not in the console.** That document's
"Block 3 · arm the deep-link replay" quotes the `LATE_RENDERED` line verbatim
and `TranslationConsoleTest` asserts the quote matches the console. The same
anchor and the same replacement as block 3 above; it occurs once in that file.

---

## What no longer needs doing

- **No route change.** `routes/payments-tamara.php` and `routes/payments-tabby.php`
  are already required by `routes/web.php` (lines 389 and 379), inside the
  existing `admin-api` group that carries `auth:admin` and `NoStoreAdminApi`.
  Verified against the real router rather than assumed —
  `TamaraConsoleReachTest > it answers every one of those five paths from the
  real router` walks `Route::getRoutes()` for all seven signatures.
- **No capability change.** All five Tamara paths are covered by the existing
  `['*', 'admin-api/payments/tamara', 'payments.manage']` and
  `['*', 'admin-api/payments/tamara/*', 'payments.manage']` rules, and Tabby's
  two by their own pair. **This lane adds no endpoint, so it adds no
  capability**, and an unmapped admin route would be owner-only at runtime in
  any case.
- **The `clear_caches_*` migration is already written** —
  `database/migrations/2027_05_09_000000_clear_caches_gateway_webhooks.php`,
  and it ships in this lane. Not for a route change (there is none) but for the
  OTHER staleness `2026_12_20_000001_clear_caches_set_appearance.php` sets out:
  a changed `app.blade.php` and a new partial are compiled into
  `storage/framework/views` keyed by PATH, and the freshness check is an mtime
  compare that an unzip's timestamps do not reliably win. A stale compiled
  console is this lane's own defect arriving a second time — the package lands
  complete and the admin draws yesterday's console, with no row in the sidebar
  and nothing 500ing anywhere.
- **No new setting, and no default moved.** Rule 1 holds trivially here: the
  screen reads state the gateway already stores and writes only when a button is
  pressed.
