# Lane WA · the nine edits the integrator applies

`routes/web.php` and `resources/views/admin/app.blade.php` are the integrator's
files, so **Appearance → WhatsApp button** is delivered as anchor/replacement
blocks. Blocks 6–9 keep three other lanes' handover records and one sidebar pin
in step with block 4/3 — they must land **in the same commit**, or
`GridSectionConsoleReachTest`, `TranslationConsoleTest` and
`AdminSidebarIsCompleteAtBuildTest` go red.

**Apply them mechanically:**

```bash
php tools/wa-apply-blocks.php      # checks every anchor count first; writes nothing if one is off
```

It reads THIS file through `Tests\Support\WhatsAppButtonHandover`, the same
parser `WhatsAppButtonScreenTest` uses, so the record and the edits cannot
drift. A block whose replacement is already present is skipped, so running it
twice is harmless.

Each anchor states how often it occurs. Blocks 4, 6, 7 and 8 use the short
anchor `'pagewash','searchterms',` rather than the whole `LATE_RENDERED` line,
so another lane lengthening that line does not break them. It is a `Set`; the
position means nothing.

| # | Where | What | Without it |
| --- | --- | --- | --- |
| 1 | `routes/web.php` | mount GET/POST `/admin-api/whatsapp-button` | every control 404s |
| 2 | `const TITLES={…}` | breadcrumb + title for `wabutton` | `?go=wabutton` opens the dashboard |
| 3 | `const LATE_NAV=[…]` | the sidebar row at build time | the row appears only at the end of the document |
| 4 | `const LATE_RENDERED=…` | arm the deep-link replay | `#wabutton` on a cold load lands on the dashboard |
| 5 | the includes | load the screen | nothing draws |
| 6–8 | BG / GS / T1B handover docs | the same insertion as block 4 | two other lanes' tests go red |
| 9 | `AdminSidebarIsCompleteAtBuildTest` | `'wabutton'` after `'pagewash'` | that pin goes red |

**No `NAV` edit**: the partial registers its row through
`window.kbbAddNavEntry()`; block 3 is the build-time copy of that call.

**No capability edit**: `wabutton.manage` (owner, manager, editor) and the
`admin-api/whatsapp-button` rule ship in `App\Support\AdminCapabilities`.

---

## Block 1 · mount the two endpoints

**File:** `routes/web.php`

**Anchor** (occurs once):

```
        require __DIR__.'/page-wash-admin.php';
```

**Replacement:**

```
        require __DIR__.'/page-wash-admin.php';
        require __DIR__.'/whatsapp-button-admin.php';
```

## Block 2 · the breadcrumb and the page title

**File:** `resources/views/admin/app.blade.php`

**Anchor** (occurs once):

```
'spotted':['Appearance','#KBeautyBliss Spotted'],
```

**Replacement:**

```
'spotted':['Appearance','#KBeautyBliss Spotted'],'wabutton':['Appearance','WhatsApp button'],
```

## Block 3 · the sidebar row, where the sidebar is built

**File:** `resources/views/admin/app.blade.php`

**Anchor** (occurs once):

```
  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/><path d="M8 11h6"/><path d="M11 8v6"/>'}
```

**Replacement:**

```
  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/><path d="M8 11h6"/><path d="M11 8v6"/>'},
  {screen:'wabutton',label:'WhatsApp button',group:'Appearance',after:['pagewash','dividers','prodstyles','homepage'],icon:'<path d="M4.5 19.5 6 15.6A8 8 0 1 1 9 18.6z"/><path d="M9.5 9.5c.4 2.2 2.3 4.4 5 5"/>'}
```

## Block 4 · arm the deep-link replay

**File:** `resources/views/admin/app.blade.php`

**Anchor** (occurs once — inside `const LATE_RENDERED=new Set([…])`):

```
'pagewash','searchterms',
```

**Replacement:**

```
'pagewash','wabutton','searchterms',
```

## Block 5 · include the screen

**File:** `resources/views/admin/app.blade.php`

**Anchor** (occurs once):

```
@include('admin.partials.import-parts-screen')
```

**Replacement:**

```
@include('admin.partials.import-parts-screen')

{{-- Appearance -> WhatsApp button (Lane WA). The floating chat button the
     owner chose (design G, "Orbit team") and every control he asked for, with
     a live preview built from the shop's own stylesheet.

     IT REGISTERS ITS OWN SIDEBAR ROW through kbbAddNavEntry(); the LATE_NAV
     row is the copy of that call the sidebar needs at build time.

     The button SHIPS ON -- he asked for it, CLAUDE.md rule 1 -- so applying
     the package puts it on the shop; this screen is how he changes or removes
     it. --}}
@include('admin.partials.whatsapp-button-screen')
```

## Block 6 · keep Lane BG's record of that line in step

**File:** `docs/BG-ADMIN-APP-BLOCKS.md`

**Anchor** (occurs once):

```
'pagewash','searchterms',
```

**Replacement:**

```
'pagewash','wabutton','searchterms',
```

## Block 7 · keep Lane GS's two records of that line in step

**File:** `docs/GS-ADMIN-APP-BLOCKS.md`

**Anchor** (occurs twice — replace both; they are its blocks 4 and 5):

```
'pagewash','searchterms',
```

**Replacement:**

```
'pagewash','wabutton','searchterms',
```

## Block 8 · keep Lane T1B's record of that line in step

**File:** `docs/T1B-ADMIN-APP-BLOCKS.md`

**Anchor** (occurs once):

```
'pagewash','searchterms',
```

**Replacement:**

```
'pagewash','wabutton','searchterms',
```

## Block 9 · advance the settled Appearance menu by one row

**File:** `tests/Feature/AdminSidebarIsCompleteAtBuildTest.php`

**Anchor** (occurs once):

```
        // names the same four in the same order.
        'Appearance' => ['homepage', 'hpcontent', 'banners', 'gridsections', 'prodstyles', 'mobilehdr',
            'dividers', 'pagewash', 'cartpanel', 'cartpage', 'checkoutpage', 'slimfooter', 'acctpanel',
```

**Replacement:**

```
        // names the same four in the same order.
        //
        // 'wabutton' — Appearance → WhatsApp button (Lane WA), one insertion:
        // its first anchor is 'pagewash', so it lands directly after it.
        'Appearance' => ['homepage', 'hpcontent', 'banners', 'gridsections', 'prodstyles', 'mobilehdr',
            'dividers', 'pagewash', 'wabutton', 'cartpanel', 'cartpage', 'checkoutpage', 'slimfooter', 'acctpanel',
```

---

## After applying

```bash
KBB_WP_DB=kbb_wp_<lane> vendor/bin/pest --compact --filter='WhatsAppButton|AdminNavAndIds|AdminSidebarIsCompleteAtBuild|AdminDeepLink|EverythingIsMountedOnce|AdminConsoleControlsAreLive|TranslationConsole|GridSectionConsoleReach|MarketingEmailsScreen'
```

The package also needs the two migrations this branch ships:
`2027_07_31_200000_clear_caches_whatsapp_button.php` (route table, compiled
Blades, config cache) and `2027_07_31_200100_seed_whatsapp_button_arabic_drafts.php`.
