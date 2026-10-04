# CT · wiring Growth & Marketing → Cart Tracking

Lane CT may not edit `routes/web.php` or `resources/views/admin/app.blade.php`.
These **five** blocks are the remainder. Each is an exact ANCHOR (occurs exactly
once at the tip this branch was cut from, a441b1a) and an exact REPLACEMENT.

`python3 tools/ct-wiring.py apply` applies all five mechanically and
`python3 tools/ct-wiring.py check` says which are present. The lane applied
them to measure the finished state (suite, screenshots) and reverted both files.

`tests/Feature/CartTrackingBlockTest.php › it is wired exactly once` and
`AdminSidebarIsCompleteAtBuildTest › declares the same row the partial does`
are RED until blocks 1–5 are applied and green after — they pin the finished
state, not its absence.

## 1 · routes/web.php — the ten admin endpoints

Anchor:

```
        require __DIR__.'/security-admin.php';
```

Replacement:

```
        require __DIR__.'/security-admin.php';

        // Growth & Marketing → Cart Tracking (Lane CT). Same group and the
        // same reason as Security: every row carries a shopper's IP address.
        // carttracking.view / carttracking.block, owner and manager.
        require __DIR__.'/cart-tracking-admin.php';
```

## 2 · app.blade.php — `const LATE_NAV=[…]`, the sidebar row

Anchor (the Search Terms row, the last in the array, which gains a comma):

```
  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/><path d="M8 11h6"/><path d="M11 8v6"/>'}
```

Replacement:

```
  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/><path d="M8 11h6"/><path d="M11 8v6"/>'},
  {screen:'carttracking',label:'Cart Tracking',group:'Growth & Marketing',after:['searchterms','pixels','meta','labels','newsletter'],icon:'<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M6 6 5 3H2"/><path d="m11 10 2 2 3-3"/>'}
```

## 3 · app.blade.php — `const TITLES={…}`, breadcrumb and title

Anchor: `'searchterms':['Growth & Marketing','Search Terms']};`

Replacement: `'searchterms':['Growth & Marketing','Search Terms'],'carttracking':['Growth & Marketing','Cart Tracking']};`

## 4 · app.blade.php — `LATE_RENDERED`, so `?go=carttracking` opens the screen

Anchor: `'gridsections','pagewash','searchterms','emails'`

Replacement: `'gridsections','pagewash','searchterms','carttracking','emails'`

## 5 · app.blade.php — the include

Anchor:

```
@include('admin.partials.search-terms-screen')
```

Replacement:

```
@include('admin.partials.search-terms-screen')
{{-- Growth & Marketing → Cart Tracking (Lane CT). After Search Terms, whose
     row its sidebar entry anchors on. --}}
@include('admin.partials.cart-tracking-screen')
```

## The package

`database/migrations/2027_07_30_200900_clear_caches_cart_tracking.php` clears
the route cache, compiled views, config/services and opcache. The package also
carries `public/build` (app.js rebuilt: cart.js and checkout.js send the
`X-KBB-Hm` header) and four schema migrations that `kbb:package` must declare.
