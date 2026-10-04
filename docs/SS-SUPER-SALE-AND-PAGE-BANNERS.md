# Lane SS · /super-sale/ in the old site's order, and Pages → Page banners

## For the integrator: wiring

Run this once, in the same commit as the merge:

```bash
php tools/ss-wire.php          # checks every anchor count first; writes nothing if one is off
```

It applies the eight edits in `docs/ss-wiring.json`. `PageBannersWiringTest`
applies the same JSON in memory, so the record and the edits cannot drift.

| # | File | Edit |
| --- | --- | --- |
| 1 | `routes/web.php` | `require __DIR__.'/page-banners-admin.php';` directly under `require __DIR__.'/page-editor-admin.php';` (inside the guarded `admin-api` group) |
| 2 | `admin/app.blade.php` NAV | `['pagebanners','Page banners','<icon>']` after the `pages-user` row in the Pages group |
| 3 | `admin/app.blade.php` TITLES | `'pagebanners':['Pages','Page banners'],` after `'pages-user':['Pages','User pages'],` |
| 4 | `admin/app.blade.php` LATE_RENDERED | `'carttracking','seokeywords','pagebanners','emails',` |
| 5 | `admin/app.blade.php` includes | `@include('admin.partials.page-banners-screen')` after the WhatsApp button include |
| 6–8 | `docs/GS-`, `BG-`, `T1B-ADMIN-APP-BLOCKS.md` | the same LATE_RENDERED change, because those records quote the whole line |

No `kbbAddNavEntry` call: the screen's sidebar row is the static NAV entry in
block 2. The capability (`pagebanners.manage`: owner, manager, editor) and its
`admin-api/page-banners` rule are already in `App\Support\AdminCapabilities`.

Checked with the edits applied: AdminNavAndIdsTest, AdminSidebarIsCompleteAtBuildTest,
AdminDeepLinkTest, GridSectionConsoleReachTest, TranslationConsoleTest,
WhatsAppButtonScreenTest, PageWashScreenTest, MarketingEmailsScreenTest,
ContentPageEditorTest, AdminRolesTest, AdminMediaPickerEverywhereTest: 192 passed;
and EverythingIsMountedOnceTest, AdminConsoleControlsAreLiveTest, AdminResetGuardTest
and this lane's tests. **Those first two are red until `tools/ss-wire.php` runs**
(they count the require and the include), which is the signal they exist for.

## What /super-sale/ was, and is now

kbeautybliss.com serves its product categories at the site root with the
category base stripped (`/skincare/`, `/toners/`; see `LegacyCategoryUrls`). The
catalogue export carries "Super Sale" as a product category, and the old menu's
Sale item points at `/super-sale/`. So the old page is the WooCommerce category
archive "Super Sale", in WooCommerce's Default sorting — `menu_order ASC, title
ASC` — where `menu_order` is the Rearrange Products plugin's order, imported
here as `products.position`.

This shop's `/super-sale/` listed every reduced product, biggest discount first.
It now lists the category slugged `super-sale` by `position`, then name, then id
(`App\Support\SuperSale`). With no such category, or none of its products
visible, it falls back to the old list rather than an empty page.

**Not verified against the live page.** `kbeautybliss.com` is refused by this
session's egress proxy (403 on CONNECT), so the order is derived from the
catalogue export, not read off the page. From `storage/catalog/products.json`
(a 22-product sample, not the full catalogue) the Super Sale order is:

| # | position | product |
| --- | --- | --- |
| 1 | 0 | House of Hur – Moist Ampoule Blusher |
| 2 | 19 | Medicube – PDRN Glow Booster Set (Pink Edition) |
| 3 | 20 | Medicube – PDRN Glow Booster Set (Black Edition) |
| 4 | 64 | Shark CryoGlow Under-Eye Cooling + LED Mask *(full price — was missing here)* |
| 5 | 118 | medicube – AGE-R Booster Pro X2 Pink *(full price — was missing here)* |
| 6 | 157 | Medicube – Collagen Booster Set – Pink Edition |
| 7 | 158 | Medicube – Collagen Booster Set |
| 8 | 178 | Medicube – Kojic Acid Turmeric Booster Pro Set |
| 9 | 209 | STYLPRO Fabulous Firmer Neck & Face Smoother |
| 10 | 266 | STYLPRO Wavelength LED Face Mask |
| 11 | 274 | Medicube – AGE-R Booster Pro |
| 12 | 275 | Medicube – AGE-R Booster Pro – Pink Edition |
| 13 | 276 | medicube – AGE-R Booster Pro Mini Pink |
| 14 | 303 | Medicube – PDRN Glow Booster Set – Mini |
| 15 | 626 | ilso – Deep Clean Master |
| 16 | 628 | StylPro Facial Steamer |

## Where the controls are

- **Pages → Page banners → Banners**: Banner name; Picture → Desktop picture /
  Phone picture (Choose from Media Library, Remove); Alt text; Link (optional);
  Strip beneath the picture → Show the strip, items (text, Arabic optional, ↑ ↓ ✕,
  + Add an item), Strip colour, Text colour, Tick colour, Strip height · desktop /
  phone, Text size · desktop / phone, Tick size · desktop / phone; Strip back to
  defaults; Delete this banner; + New banner.
- **Pages → Page banners → Where they show**: one banner select per custom page
  (Super Sale, New In, Best Sellers, Everything under AED 54, every routed content page).
- **Pages → Page banners → Super Sale products**: Products on /super-sale/.
- Campaign membership: **Catalog → Product editor → Categories** (tick Super Sale).
  Campaign order: **Catalog → Catalog → Reorder**, choose Super Sale.
