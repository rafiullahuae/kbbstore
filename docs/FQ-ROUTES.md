# Lane FQ — two route observations, neither of them applied

This lane added no route. `routes/web.php` is the integrator's, so what follows
is written out rather than edited, and neither item is a defect this lane
introduced.

---

## 1. Phase 9's `/skincare-guide/` item is already done

The plan still carries it as open:

> `/skincare-guide/` — a permalink structure, not one page: the homepage builds
> `/skincare-guide/{slug}/`, the router serves `/blog` and `/post/{slug}`

**That is stale.** 2.60.109 settled it. Established by fetching every shape
against a running preview rather than by reading the router:

| URL | answers |
|---|---|
| `/skincare-guide/` | **200** — the Journal index, self-canonical |
| `/<slug>/` | **200** — the article, at the site root, matching the live WordPress addresses |
| `/blog`, `/blog/` | 301 → the Journal index |
| `/post/{slug}` | 301 → `/<slug>/` |
| `/post/`, `/post` | 301 → the Journal index |
| `/skincare-guide/{slug}/` | 301 → `/<slug>/` |

The sitemap carries `/skincare-guide/` and `/ar/skincare-guide/` and the two
article URLs, and **no** `/blog` and **no** `/post/` entry. One canonical
address per document, 301s from the rest, and only the canonical advertised —
which is exactly what the plan asked for.

The plan line can be ticked. This lane may not edit the plan.

## 2. The one thing left on it, which is small and is an inconsistency, not a bug

`routes/web.php`:

```php
Route::get('/blog', fn () => redirect()->route('blog', [], 301));
```

`route('blog')` produces `/skincare-guide` **without the trailing slash**,
because Laravel's `UrlGenerator::format()` rtrims it off a registered URI.
`/skincare-guide` then answers 200 on its own and self-canonicalises to
`/skincare-guide/`. So the 301 lands on a URL that is not the canonical one.

Nothing is broken — there is no second redirect and no loop, and the canonical
tag on the landing page resolves it. But `BrandController::legacyIndex()`
already refuses to do this, in a comment that names the reason:

> `Url::redirect()` rather than `route()`: Laravel strips the trailing slash
> when it registers a URI, so `route('brands.index')` hands back
> `/korean-skincare-brands` and the 301 would land on a URL that is not the
> canonical one. U-01 keeps the slash […]

Two lines in the same file disagree about the same rule. The fix is the one
already written next door.

**Anchor** — appears once:

```php
Route::get('/blog', fn () => redirect()->route('blog', [], 301));
Route::get('/post/{slug?}', fn (string $slug = '') => $slug === ''
    ? redirect()->route('blog', [], 301)
    : redirect()->route('post', ['slug' => $slug], 301));
```

**Replacement:**

```php
// Url::redirect(), not route(): Laravel's UrlGenerator rtrims the trailing
// slash off a registered URI, so route('blog') hands back /skincare-guide and
// the 301 lands on an address that is not the canonical one — which then
// answers 200 and canonicalises to the slashed form, a hop this does not have
// to cost. URL Contract U-01 keeps the slash, and Url::redirect() also applies
// the deployment prefix exactly once. Store\BrandController::legacyIndex()
// carries the same reasoning for /brands/.
Route::get('/blog', fn () => redirect(\App\Support\Url::redirect('/skincare-guide/'), 301));
Route::get('/post/{slug?}', fn (string $slug = '') => $slug === ''
    ? redirect(\App\Support\Url::redirect('/skincare-guide/'), 301)
    : redirect(\App\Support\Url::redirect('/' . $slug . '/'), 301));
```

**This needs a `clear_caches_*` migration in whatever package carries it** — it
edits `routes/web.php`, and a compiled route cache on the server would go on
serving the old closures. CLAUDE.md, "How this ships".

It is NOT applied here and nothing in this lane depends on it. It is worth
ninety seconds when somebody is next in that file, and is not worth a package
of its own.
