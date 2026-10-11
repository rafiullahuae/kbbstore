# Patch reference — two real packages, exactly as the owner received them

These are not examples written for the handover. They are the actual zips
handed to the owner on 11 October 2026 and applied through his admin. Copy
their shape; never invent a new one.

| File | Release | Built from | `--since` | Why it is here |
|---|---|---|---|---|
| `kbb-update-2.60.485.zip` | Cart panel free-delivery bar | commit `1ca5166f` | `5ddbb4ac` | The smallest normal package: one code file and its cache-clearing migration |
| `kbb-update-2.60.484.zip` | Marketing footer words + locked note | commit `5ddbb4ac` | `f7ab3364` | A package that changed an admin screen, so it also carries the rebuilt `public/build/` assets and manifest |
| `2.60.485-unpacked/` | the first zip, unzipped | | | to read without unzipping |

Re-check either one at any time, which also proves the verifier still works:

```bash
sh tools/verify-package.sh docs/patch-reference/kbb-update-2.60.485.zip 5ddbb4ac 1ca5166f
sh tools/verify-package.sh docs/patch-reference/kbb-update-2.60.484.zip f7ab3364 5ddbb4ac
# both end: ALL CHECKS PASSED — safe to hand to the owner.
```

## Anatomy

```
kbb-update-2.60.485.zip
├── update.json
└── files/
    ├── app/View/Composers/CartDrawerComposer.php
    └── database/migrations/2027_10_28_200000_clear_caches_cart_panel_ship_bar.php
```

`files/` mirrors the app root: each path is where the file lands under
`/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app/`. Files under
`files/public/` land in the **web root** instead (`../public_html/`), because
the web root is a different folder from the app root on this server.

`update.json` — every key is required, and two of them are what the updater
actually acts on:

```json
{
    "name": "KBB Storefront",
    "version": "2.60.485",
    "requires_php": "8.2",
    "notes": "",
    "files": {
        "app/View/Composers/CartDrawerComposer.php": "<sha256 of the file>",
        "database/migrations/2027_10_28_200000_clear_caches_cart_panel_ship_bar.php": "<sha256>"
    },
    "migrations": true,
    "signature": ""
}
```

- **`files`** — path → SHA-256. The shop refuses a zip whose payload does not
  match these checksums, and refuses any path outside the allowed areas
  (`App\Services\Update\UpdateGuard`).
- **`migrations`** — the ONLY thing that decides whether `php artisan migrate`
  runs after the files are copied. It must be `true` whenever `files/` carries a
  migration. Five hand-built packages once said `false` (or nothing) and eight
  migrations were copied to the live server and never ran; the shop now refuses
  that shape, and `tools/verify-package.sh` checks it too.
- **`signature`** — `""` means unsigned. The shop is in *permissive* mode
  (`KBB_UPDATE_SECRET` unset; Ed25519 signing exists but is on hold by the
  owner's choice — `docs/PACKAGE-SIGNING.md`). `kbb:package` prints "No signing
  key found, so this package is UNSIGNED" on every build; that line is expected.
- **`notes`** — left empty. The release notes live in `docs/CHANGELOG.md` and in
  the message to the owner. (Notes once broke the Core Updates screen by
  containing angle brackets; the screen now escapes them, but empty is safest.)

## What a package never contains

`kbb:package` leaves these out on purpose (`BuildPackage::NEVER_SHIP`):
`tests/`, `docs/`, `tools/`, `CLAUDE.md`, `KBB-Master-Plan.md`, `VERSION`,
`composer.lock`, `package*.json`, `vendor/`, `node_modules/`, `.env`,
`wordpress-plugin/`, `public-web-root/`, `env.staging.txt`. The live server's
installed version is the newest applied row in its `update_releases` table, not
a `VERSION` file.

A package also never **deletes** a file. Old compiled assets in
`public/build/assets/` simply stay on the server, unused, because the new
`manifest.json` no longer points at them. That is expected and harmless.

## Every cache-clearing migration looks like this

Copy the newest `database/migrations/*_clear_caches_*.php`, give it the next
timestamp (they run ahead of the calendar on purpose — after
`2027_10_28_200000` comes `2027_10_28_3xxxxx` or later) and change only the
comment. It deletes compiled views, the config/route/event caches and resets
OPcache, so the shop never serves yesterday's compiled copy. Every package that
changes code, a view or a route ships one.
