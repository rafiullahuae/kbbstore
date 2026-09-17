# Lane FQ — the one change this lane could not make itself

`bootstrap/app.php` is on this lane's do-not-edit list, and it is on
`BuildPackage::NEVER_SHIP` as well, so it reaches the server **by hand and never
by package**. That is the right arrangement — "a bad `bootstrap/app.php` stops
the application booting at all, which would leave the updater unable to roll
itself back" — and it is why this is written out rather than applied.

Until it is applied, `App\Http\Middleware\CacheHeaders` is inert: the class
exists, it is tested, and nothing calls it. Nothing else in this lane depends on
it, so merging the rest without this loses only the cache-header work.

The policy it enforces, and the half of it that no PHP change can enforce, are
in `docs/IMAGE-PIPELINE-AND-CACHE.md` §9.

---

## The edit

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

**No `clear_caches_*` migration is needed for this one.** The rule in CLAUDE.md
is about `routes/web.php` and the compiled ROUTE cache; this adds no route and
changes no route file. `bootstrap/app.php` is read on every boot and is not
compiled into anything.

## How to check it landed

Fetch a storefront page and an account page from the live site and read the
header:

```
/shop/        Cache-Control: max-age=0, must-revalidate, no-cache, private
/my-account   Cache-Control: max-age=0, must-revalidate, no-cache, no-store
```

Symfony reorders the directives on the way out, so compare the **set**, not the
string. `tests/Feature/CacheHeaderPolicyTest.php` does the same and says why.

## What this lane changed nowhere near `routes/web.php`

Nothing. Lane FQ added no route. The only other thing it has for an integrator
is `docs/cache-headers.htaccess`, which is a file for a **human** to place in
the web root's `build/` and `img-cache/` directories through the hosting file
manager — never a package, and never the web root itself. §9.4 of the pipeline
document says what to check before placing it and what to do if it 500s.
