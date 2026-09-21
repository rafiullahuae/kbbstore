# Lane FQ — the one change this lane could not make itself

> **SUPERSEDED, AND KEPT FOR THE RECORD.** The hand-edit below was never made,
> and the middleware it registers sat inert for the whole life of this document.
> `App\Providers\AppServiceProvider::boot()` now appends `CacheHeaders` to the
> `web` group from a file a package **can** ship, exactly as it already does for
> `SetLocaleFromPath` and `CanonicalHost` and for exactly the same reason. There
> is nothing left here for anybody to apply.
>
> **Do not delete the file from the server if the edit WAS applied at some
> point.** `appendMiddlewareToGroup()` does an `array_search` before it appends,
> so a `bootstrap/app.php` carrying the line and a provider doing the same thing
> produce one registration, not two — and
> `tests/Feature/CacheControlScreenTest.php` asserts exactly that count.
>
> **And it is now switched off by default.** Registering the middleware changes
> the `Cache-Control` header on every page of a shop taking orders, so
> `cache.headers_enabled` ships `false` and the middleware returns every
> response untouched until an owner turns it on from **Platform → Cache**.
> `App\Support\CacheSettings` carries that argument in full.

---

`bootstrap/app.php` is on this lane's do-not-edit list, and it is on
`BuildPackage::NEVER_SHIP` as well, so it reaches the server **by hand and never
by package**. That is the right arrangement — "a bad `bootstrap/app.php` stops
the application booting at all, which would leave the updater unable to roll
itself back" — and it is why this was written out rather than applied.

The policy it enforces, and the half of it that no PHP change can enforce, are
in `docs/IMAGE-PIPELINE-AND-CACHE.md` §9.

---

## The edit, as it was written

One line, added to the existing `$middleware->web(append: [...])` list in
`bootstrap/app.php`.

**Anchor** — exactly this, and it appears once:

```php
        // Baseline security headers on every web response.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
```

**Replacement:**

```php
        // Baseline security headers on every web response.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            /*
             * Says what may be cached, instead of letting Symfony's default
             * decide it. Every storefront response already leaves as
             * "no-cache, private" because ResponseHeaderBag computes that for
             * a response carrying no Cache-Control of its own -- the right
             * answer, arrived at by accident, on the pages that carry a cart
             * badge and a CSRF token. NoStoreAdminApi's own note records that
             * shared hosting commonly caches GET responses by default.
             *
             * It also moves the customer's own pages -- account, wishlist,
             * cart, checkout -- from no-cache to no-store, so a back button
             * after a sign-out on a shared machine cannot render them off disk.
             *
             * APPENDED, so it runs late and sees the response every other
             * middleware has finished with. It leaves alone anything that
             * already carries a Cache-Control of its own, which is what keeps
             * the admin console's no-store and NoStoreAdminApi's no-store
             * where they are. docs/IMAGE-PIPELINE-AND-CACHE.md §9.
             */
            \App\Http\Middleware\CacheHeaders::class,
```

**No `clear_caches_*` migration was needed for that one.** The rule in CLAUDE.md
is about `routes/web.php` and the compiled ROUTE cache; it adds no route and
changes no route file. `bootstrap/app.php` is read on every boot and is not
compiled into anything.

**One IS needed for the provider registration that replaced it**, and for a
reason worth writing down: the compiled `services.php` decides which providers
boot at all, so a stale one is a shop where the new Cache screen offers a switch
that reaches nothing. See
`database/migrations/2026_11_27_000001_clear_caches_cache_control.php`.

## How to check it landed

Open **Platform → Cache** in the admin. The first card fetches a page from the
shop through the shop's own code and prints the `Cache-Control` it answered
with, beside whether the middleware is in the request pipeline and whether the
switch is on. That is the check; the two fetches below are what it does.

```
/shop/        Cache-Control: max-age=0, must-revalidate, no-cache, private
/my-account   Cache-Control: max-age=0, must-revalidate, no-cache, no-store
```

Those are the values **with the switch on**. With it off — which is how it ships
— both answer `no-cache, private`, which is what they answered before any of
this existed. Symfony reorders the directives on the way out, so compare the
**set**, not the string. `tests/Feature/CacheHeaderPolicyTest.php` does the same
and says why.

## What this lane changed nowhere near `routes/web.php`

Nothing. Lane FQ added no route. Its only other item for an integrator was
`docs/cache-headers.htaccess`, which is a file for a **human** to place in the
web root's `build/` and `img-cache/` directories through the hosting file
manager — never a package, and never the web root itself. §9.4 of the pipeline
document says what to check before placing it and what to do if it 500s.

The Cache screen now prints that file's directives on the page, built in PHP
rather than read off disk, because `docs/` is on `BuildPackage::NEVER_SHIP` and
therefore does not exist on a customer's server —
`tests/Feature/CacheControlScreenTest.php` holds the printed text against this
repository's copy so the two cannot drift.
