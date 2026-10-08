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
| **Repo synced to** | **2.60.438** |
| Date | 2026-10-08 |
| Previous entry point | 2.60.437 |
| Server version at sync | **NOT KNOWN.** See the gap below — this is the one thing this file exists to record and it is open again |
| Verified how | The 2.60.325 package was built from `3b372db` with `php artisan kbb:package`, and all **155** files in it were compared byte-for-byte against that tree before it was handed over. `UpdatePackage::verify()` accepts it |

> ▲ **STEP 1 BELOW WAS SKIPPED FOR 218 VERSIONS.** This table read 2.60.107 and
> 13 September while the tree moved to 2.60.325. The file's own closing line
> says what that costs: *"A drop that skips step 1 puts the repo back where it
> was: correct content, unknown state."* That is exactly what happened, and it
> is the 2.60.36 episode this file was written for, repeated at eight times the
> distance. The tree is not in doubt — every package since has been built from a
> tagged commit and diffed against it — but **which of them the live shop has
> actually had applied is not recorded anywhere, and cannot be recovered from
> this repository.**

`VERSION` at the repo root carries the same number in one line, for anything
that wants to read it mechanically.

---

## What is tracked

`app/` · `bootstrap/` · `config/` · `database/` · `public/` (including
`public/build/`) · `public-web-root/` · `resources/` · `routes/` · `tests/` ·
`tools/` · `wordpress-plugin/` · `composer.json` · `composer.lock` ·
`package.json` · `package-lock.json` · `vite.config.js` · `phpunit.xml` ·
`phpunit-mysql.xml` · `docs/` · `CLAUDE.md` · `KBB-Master-Plan.md` ·
`KBB-Progress-Dashboard.html` · `VERSION` · `README.md` · `.github/`

**The list above was four entries short**, which matters because this file is
what a reader consults to know whether something is in the repository at all:
`tests/` (837 files), `tools/` (265), `wordpress-plugin/` and `public-web-root/`
were all tracked and none of them was named. `public/build/` is tracked
deliberately and **must be committed by hand** — `package.json` defines no
`build` script, CI does not build assets, and a rule added under `resources/css`
therefore ships **inert** until somebody runs `npx vite build`.

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

- **▲ WHICH VERSION THE LIVE SHOP IS RUNNING IS NOT RECORDED ANYWHERE.** Every
  package from 2.60.108 to 2.60.325 was built and handed over; none of them was
  logged here as applied, because this file stopped being updated. The shop has
  a shell now (`docs/CUTOVER-EXTRABEAUTY.md`), so this is answerable in one
  command rather than by inference — read `VERSION` in the app root, or
  `php artisan migrate:status`, and write the answer into the log below. Until
  somebody does, a package built "on top of" the live state is a guess.
- **The live shop moved and this file never said so.** It is `extrabeauty.ae` on
  Cloudways, app root
  `/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app`, with
  `KBB_BASE_PATH` **empty**. `docs/CUTOVER-EXTRABEAUTY.md` is the authority.
  Anything in this repository still naming `easywebsol.com/kbb-upgrade` is the
  OLD Hostinger box.
- **Four unauthenticated scripts in the public web root** are outside this
  repository and outside the update system. `STATUS-2.60.98.md` is referenced
  for the detail and **is not in this tree**, so that detail is currently
  unrecoverable from the repo.
- **Nothing outside the tracked paths was reviewed.** Files untouched since
  2.60.36 are assumed current because no patch has altered them, which is an
  inference, not a check. That inference is now 289 versions old.
- **Packages are not tracked.** `storage/app/` is ignored in full, so the built
  zips live only on the machine that built them. The repository is the durable
  record of *state*; the packages are how code moves, and they are not archived
  here.

**Closed since the last entry point:** the two `UpdateController.php.backup-*`
files are gone, and the documents are current again — `KBB-Master-Plan.md` was
rewritten on 30 September with thirteen lanes written into their phases, and
`KBB-Progress-Dashboard.html` regenerated from it (24 phases, 312 items, 91%).

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
| 2026-09-13 → 2026-09-29 | 2.60.108 → 2.60.324 | 2.60.107 | **217 versions, none of them logged here.** Reconstructible from `docs/CHANGELOG.md` and the commit history, which are complete; this table is not. Recorded as one row rather than invented as 217, because a row per version would claim a precision nobody has |
| 2026-09-30 | 2.60.325 | 2.60.107 | Thirteen lanes. 155 files, 14 migrations, built from `3b372db` and diffed file-by-file against it. Entry point re-established after the 218-version gap above |
| 2026-09-30 | 2.60.326 | 2.60.325 | Five fixes, no shipped default moved. Built from the commit that carries this row, and diffed file-by-file against it |
| 2026-09-30 | 2.60.327 | 2.60.326 | One fix, to a defect .326 shipped an hour earlier: a paid order's basket could be handed back. Built from the commit that carries this row |
| 2026-09-30 | 2.60.328 | 2.60.327 | Two order defects found by sweeping for .327's SHAPE: cash on delivery reported "placed" whatever happened, and cardAbandoned() carried .327's fault the whole time. Built from the commit that carries this row |
| 2026-09-30 | 2.60.329 | 2.60.328 | A twelfth and thirteenth navigation, found by widening a pin rather than by searching, and the file cache scoped per database. Built from the commit that carries this row |
