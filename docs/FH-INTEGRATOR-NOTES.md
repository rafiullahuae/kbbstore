# Lane FH · integrator notes

**There are no anchor/replacement blocks for this lane.** Nothing it needs
touches a file it may not edit. This file exists so that is a *recorded finding*
rather than an omission the integrator has to infer.

## The five forbidden files, and why each is untouched

| File | Why nothing is needed |
| --- | --- |
| `routes/web.php` | **No new routes.** Every endpoint this lane changes already exists and is already required: `brands-admin.php` at line 383, `catalog-admin.php` at 427, `categories-brands-admin.php` at 433. The lane changes the *bodies* of `BrandsApiController::validated()` and `CategoriesApiController::validated()`, not the routing to them. No `clear_caches_*` migration is needed either, for the same reason — the compiled route cache is still correct. |
| `resources/views/admin/app.blade.php` | The two screens edited are **already included partials**: `@include('admin.partials.category-tree-screen')` at 19347 and `@include('admin.partials.brands-editor-screen')` at 19357. The new fields are inside those partials' own markup builders. |
| `bootstrap/app.php` | Untouched. `usePublicPath()` is read, never written — the image policy documents why the web root is a different directory, and changes nothing about it. |
| `KBB-Master-Plan.md` | Not edited. Phase 12's three lines are addressed; the integrator merges the plan. See the PR body / the lane report. |
| `KBB-Progress-Dashboard.html` | Not edited. |

Verified mechanically, not by eye:

```
git diff --name-only <base>..HEAD | grep -E 'routes/web.php|KBB-Master-Plan|KBB-Progress|admin/app.blade|bootstrap/app.php'
   -> no matches
```

## Capability rules: also nothing to add

`AdminCapabilities::RULES` already covers both endpoints, writes included:

```
['*', 'admin-api/categories',    'catalog.manage'],
['*', 'admin-api/categories/**', 'catalog.manage'],
['*', 'admin-api/brands',        'catalog.manage'],
['*', 'admin-api/brands/**',     'catalog.manage'],
```

No new admin path is introduced, so the "writes before reads" ordering rule has
nothing to apply to here.

## Migrations: none

`brands.seo` and `categories.seo` already exist
(`2026_10_05_add_category_seo_and_redirects`). The lane changes what is written
into them, not their shape.

**No data migration is needed and one is deliberately not shipped.** Rows
already holding the legacy `description` spelling keep working untouched:
every reader normalises through `ProductSeo::normalise()`, whose `RENAME` maps
`description` to `desc`, and the new write path preserves whatever spelling a
row already carries rather than rewriting it. See the vocabulary note in the
lane report for the one thing an integrator may want to decide later.
