# Every admin screen now has a URL

`?go=<id>` and `#<id>` opened the **Dashboard** for eleven screens. Not with an
error and not under the wrong heading — under *Overview · Dashboard*, with the
dashboard's own body under it and nothing anywhere for the owner to report. Nine
of the eleven have a sidebar row that works perfectly when clicked.

Found by Lane V4, which had to call `window.go('ugcvideo')` by hand to photograph
its own screen; the other seven were found by the guard written for that one
(`AdminNavAndIdsTest > it routes every screen a partial declares…`).

The cause is one step earlier than the `LATE_RENDERED` note in `app.blade.php`
describes. That note is about an id which IS in `TITLES` and has no renderer.
These eleven were **not in `TITLES` at all**, and `TITLES` is what the deep-link
boot consults to decide whether an id routes — absent from it, the id was not a
screen as far as the console was concerned.

## Measured, in Chromium, through the real console

`tests/browser/admin-deep-links.mjs` drives each id three ways in one session —
sidebar click, `?go=`, `#hash` — and instruments `#content.innerHTML` and
`window.fetch` before any page script parses.

### Before

| id | sidebar row | click → | `?go=` → |
|---|---|---|---|
| ugcvideo | no | — | **Overview · Dashboard** |
| ugcsections | yes | Content · Shoppable video | **Overview · Dashboard** |
| ugcstyle | no | — | **Overview · Dashboard** |
| instagram | yes | Content · Instagram | **Overview · Dashboard** |
| cache | yes | Platform · Cache | **Overview · Dashboard** |
| cartpage | yes | Appearance · Cart page | **Overview · Dashboard** |
| checkoutpage | yes | Appearance · Checkout page | **Overview · Dashboard** |
| routines | yes | Catalog · Build my routine | **Overview · Dashboard** |
| security | yes | Store · Security | **Overview · Dashboard** |
| sitelayout | yes | Appearance · Site layout | **Overview · Dashboard** |
| slimfooter | yes | Appearance · Footer | **Overview · Dashboard** |

### After

All eleven: **click, `?go=` and `#hash` give the same heading and the same
`#content` body, byte for byte.** No page errors at either width.

### And not drawn twice, which is the risk of arming one

Arming an id replays the navigation after the partial has installed its renderer,
so the thing to prove is that nothing mounts twice. Measured per navigation:

- **Repeated `/admin-api/` requests: none, on any of the eleven, by any route.**
  A screen mounted twice asks the server twice; none did.
- Writes to `#content`: the click path makes 3 (6 for `routines`); the deep-link
  path makes exactly one more, and that one is the boot's own placeholder —
  `<div class="wrap"> <div class="banner">…` — followed by the same three the
  click path makes. The marker is gone by the time the screen settles
  (`markerLeft: false` everywhere).

This is the condition `LATE_RENDERED` carries, and it holds because each of the
eleven partials calls `render()` **before** `load()` in its own `window.go`
wrapper, synchronously. `rev-all` is in neither armed set for exactly the
opposite reason: `renderReviews()` awaits `rvLoad()` before it paints, so the
marker is still there when the replay runs and the screen drew twice on two runs
in three.

### Layout

`scrollWidth === clientWidth` at 390 and at 1280 on every shot, before and after.
No horizontal overflow anywhere.

## The six that are still not routed, on purpose

`product-editor`, `coupon-editor`, `coupon-usage`, `brands-manager`,
`category-tree`, `order-new`. Each draws ONE ROW and takes which row from module
state the click set, not from the URL, so `?go=product-editor` with no product is
an editor with nothing in it — a silent dashboard turned into an empty form is
not an improvement. Real deep links for these mean an id in the address
(`#product-editor/1234`) and a read on arrival, which is a feature per screen.

They are a named list in the test rather than a predicate, and the test fails if
one of them gains a `TITLES` row without leaving the list — so the exemption
cannot outlive its reason.

## Pictures

`{before,after}-{ugcvideo,security,routines}-{390,1280}.png`, Chromium 141 at
DPR 2, plus `{before,after}-measurements.json`. Taken against a local preview
(`php -S` + `tools/ig-preview-router.php`, `KBB_PUBLIC_PATH` set — `php artisan
serve` cannot work in this checkout, see that router's header).
