# Repository state

The entry point. This file records what the repository actually contains, so
nobody has to infer it from commit messages again.

The 2.60.36 episode is why this exists: the repo held a clean, honest snapshot
of 2.60.36 while the server ran 2.60.71, and nothing in the tree said so. Half
a session went into establishing which was which.

---

## Current entry point

| | |
|---|---|
| **Repo synced to** | **2.60.107** |
| Date | 2026-09-13 |
| Previous entry point | 2.60.36 |
| Server version at sync | 2.60.98 applied; 2.60.99–.107 packaged, not yet applied |
| Verified how | Every file hashed against the update manifests; the 2.60.72–.91 changes overlaid on the 2.60.71 server state |

`VERSION` at the repo root carries the same number in one line, for anything
that wants to read it mechanically.

---

## What is tracked

`app/` · `bootstrap/` · `config/` · `database/` · `public/build/` ·
`resources/` · `routes/` · `composer.json` · `vite.config.js` · `docs/` ·
`KBB-Master-Plan.md`

## What is deliberately not tracked

| Excluded | Why |
|---|---|
| `.env` | Live Stripe, Tabby and Tamara keys. Never commit this |
| `vendor/` | Composer-managed, rebuildable from `composer.lock` |
| `node_modules/` | npm-managed |
| `storage/framework/`, `storage/logs/` | Runtime state and logs |
| `*.backup-*`, `*.before-patch-*` | Editor and patch leftovers. Two `UpdateController.php.backup-*` files are committed from before this rule and should be removed |
| `app/*.zip` | `KBB-REPAIR-UPDATER-APP-FOLDER.zip` and similar |
| `public/img-cache/` | Phone-sized copies of the shop's photographs, written into the web root by the upload endpoint and by the Media Library's batch. Generated, so always remakeable from the originals; large; and on `BuildPackage::NEVER_SHIP` as well, because a package that carried them could delete them on the next install. Deleting it costs nothing but the CPU to make them again — the tiles fall straight back to the full-size originals in the meantime |

---

## Known gaps at this entry point

- **2.60.99–.107 are in this tree but not yet applied to the server**, which is
  at 2.60.98. Uploading the 2.60.107 package makes them match. Packages
  2.60.102–.106 were withdrawn: they had been built against a stale copy of
  three files and would have reverted work already on the server. 2.60.107
  contains everything they did, on the correct base.
- **Two committed backup files should be deleted** from the repo:
  `app/Http/Controllers/Admin/UpdateController.php.backup-20260827075715` and
  `...backup-20260827080301`. They predate the `*.backup-*` ignore rule.
- **Four unauthenticated scripts in the public web root** are outside this
  repository and outside the update system. See `STATUS-2.60.98.md` §6.
- **Nothing outstanding on the documents.** `KBB-Master-Plan.md` now reads "as
  at 2.60.91" with the seven mis-ticked items corrected in the body, each
  carrying the version that shipped it or the note that it was verified as
  already built. `KBB-Progress-Dashboard.html` was regenerated from that
  corrected body: 110 items, 86 done, 24 open of which 5 are blocked.
- **Nothing outside the tracked paths was reviewed.** Files untouched since
  2.60.36 are assumed current because no patch has altered them, which is an
  inference, not a check.

---

## Moving the entry point

Each time files are pushed:

1. Update the table above — version, date, previous entry point.
2. Update `VERSION`.
3. Add the versions to `docs/CHANGELOG.md`, with the files each touched.
4. Note anything knowingly left stale under **Known gaps**.

A drop that skips step 1 puts the repo back where it was: correct content,
unknown state.

### Log

| Date | Moved to | From | Note |
|---|---|---|---|
| 2026-09-13 | 2.60.91 | 2.60.36 | 225 files. Closed a 55-version drift. First entry point recorded |
| 2026-09-13 | 2.60.91 | — | Docs pass: master plan body corrected, progress dashboard regenerated from it |
| 2026-09-13 | 2.60.98 | 2.60.91 | Security and correctness sweep: 2.60.92–.98. See STATUS-2.60.98.md |
| 2026-09-13 | 2.60.100 | 2.60.98 | Wider XSS sweep (18 more sites) and the installed-version fix |
| 2026-09-13 | 2.60.107 | 2.60.36 | Full handover: 234 files, every change since the 2.60.36 snapshot |
