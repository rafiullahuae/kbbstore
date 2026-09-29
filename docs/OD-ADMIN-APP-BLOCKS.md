# Lane OD · the one edit the integrator applies

`resources/views/admin/app.blade.php` is the integrator's file and this lane may
not edit it, so the release control is delivered as an anchor/replacement block
in the shape `docs/TM-ADMIN-APP-BLOCKS.md` established.

**There is only one block, and that is the point.** The release control is *not*
a screen. It registers no sidebar row, claims no `go()` id, wraps nothing and
has no breadcrumb — it is a panel appended to the Items card of an order screen
that already exists. So there is no `TITLES` row, no `LATE_RENDERED` entry, and
therefore **no fourth edit in `docs/T1B-ADMIN-APP-BLOCKS.md`**, which is the
edit three of four lanes have forgotten. `TranslationConsoleTest` keeps that
document and the `LATE_RENDERED` line in step and neither of them moves here.

The anchor below was verified to occur **exactly once** in the console on
`lane/od2` at the commit that ships this file.

---

## What is red until it is applied, and what is green after

Measured in this lane's worktree, both ways, on the same tree.

| | assertions red | the eight guards |
|---|---|---|
| **Before** (as this branch ships) | **2** — see below | not run; both are static file checks |
| **After** (the block applied) | **0** | **93 passed, 0 failed**, 33s |

Both red-before are the *finished-state* pin CLAUDE.md prescribes — `=== 1`,
never `->not->toContain` — so each goes green the moment the include lands and
stays a real guard afterwards:

```
EverythingIsMountedOnceTest > it includes every admin console partial exactly once
    order-release-hold                 is included 0 times

ReleaseTheHoldTest > it includes the release panel on the admin console exactly once
    the release panel is not mounted on the console exactly once
    Failed asserting that 0 is identical to 1.
```

The 93 counted above are `ReleaseTheHoldTest`, `EverythingIsMountedOnceTest`,
`AdminNavAndIdsTest`, `AdminConsoleControlsAreLiveTest`, `TranslationConsoleTest`,
`AdminConsoleJavaScriptParsesTest`, `AdminConsoleScriptParsesTest` and
`VoidEndpointIsSharedTest`, run together with the block applied.

---

## Block 1 · draw the panel

Anchor (occurs once):

```
@include('admin.partials.tamara-connection-screen')
```

Replacement:

```
@include('admin.partials.tamara-connection-screen')

{{-- Orders → (an order) → Items → "Release the hold" (Lane OD). A cancelled
     BNPL order never released the buyer's authorisation, because the button did
     not exist: routes/payments-void.php is mounted, registers GET and POST
     /admin-api/orders/{id}/void behind PaymentVoidController, is mapped to
     orders.money and is tested — and nothing in this console called either
     path, while POST .../capture, its sibling on the same card, is called a few
     hundred lines up. A cancelled Tamara order left the buyer's instalment plan
     live at Tamara for up to 180 days.

     NOT A SCREEN. No sidebar row, no go() id, no window.go wrapper, so this
     include is the whole of the change to this file. The panel appends itself
     to the Items card from the outside, through a MutationObserver on #content
     coalesced to one check per animation frame — renderOrderDetail() repaints
     the whole screen after every save, so a one-shot injection would survive
     until the first save and then vanish.

     Applying it changes nothing on the live shop: it adds no setting, moves no
     default, and releases nothing until the owner presses its confirm
     button. --}}
@include('admin.partials.order-release-hold')
```

That is the entire change to `app.blade.php`. One line of Blade plus its
comment.

---

## What does NOT need doing, and why each was checked rather than assumed

- **No route change.** `routes/payments-void.php` is already required by
  `routes/web.php` (line 403), inside the existing `admin-api` group that
  carries `auth:admin` and `NoStoreAdminApi`. Verified against the running
  router, not against the file: `ReleaseTheHoldTest > it calls a release path
  the real router answers, in both verbs` walks `Route::getRoutes()` for
  `admin-api/orders/{id}/void` and asserts both GET and POST are served.

- **No capability change.** Both verbs are already mapped —
  `['GET', 'admin-api/orders/*/void', 'orders.money']` and the POST beside it —
  and this lane adds no endpoint, so it adds no capability. Pinned twice:
  once at the map (including that both rules sit *ahead* of the broad
  `GET admin-api/orders/*` read rule, which is first-match-wins and would
  otherwise turn the release read into `orders.view`), and once over HTTP, where
  a `support` account is refused 403 on both verbs and still reads the order
  itself.

- **No `TITLES` row and no `LATE_RENDERED` entry**, for the reason at the top:
  this is a panel, not a screen. `docs/T1B-ADMIN-APP-BLOCKS.md` is untouched.

- **The `clear_caches_*` migration is already written** —
  `database/migrations/2027_05_10_000000_clear_caches_release_hold.php`, and it
  ships in this lane. Not for a route change (there is none) but for the changed
  Blade: a compiled view is keyed by PATH and the freshness check is an mtime
  compare an unzip's timestamps do not reliably win, so the package can land
  complete and the admin can draw yesterday's order screen with no control on
  it — this lane's own defect arriving a second time.

- **No new setting, and no default moved.** The panel reads state the order
  already carries and writes nothing until its confirm button is pressed.
